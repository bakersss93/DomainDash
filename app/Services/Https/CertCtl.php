<?php

namespace App\Services\Https;

use App\Models\PlatformCertificate;
use App\Models\PlatformCertificateRequest as Req;
use App\Models\Setting;
use App\Services\AuditLogger;
use App\Services\Synergy\SynergyWholesaleClient;
use App\Support\Https\CertificateException;
use App\Support\Https\CertificateValidator;
use App\Support\Https\HostnameValidator;
use App\Support\Https\LogSanitizer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * The root-run helper (php artisan domaindash:certctl, via systemd).
 *
 * Security model: the database is written by the (less trusted) web process, so NOTHING from it is
 * trusted. Every request is re-validated here, only a fixed allowlist of operations exists
 * (letsencrypt | upload | renew), all external programs are fixed absolute paths executed with
 * array arguments (no shell), and all filesystem targets are fixed directories + a validated hostname.
 */
class CertCtl
{
    public const VERSION = '1.0';
    private const STALE_RUNNING_MINUTES = 45;

    public function __construct(
        private CertificateValidator $validator,
        private ZoneFinder $zones,
        private SynergyWholesaleClient $synergy,
        private TxtResolver $resolver,
    ) {
    }

    /** One timer tick: heartbeat, fail stuck requests, process at most the pending queue, sync metadata. */
    public function tick(): array
    {
        $this->heartbeat();
        $this->failStaleRunning();

        $handled = 0;
        while ($req = Req::query()->where('status', Req::STATUS_PENDING)->orderBy('id')->first()) {
            // Claim atomically; another tick (should not happen: flock) loses the race.
            $claimed = Req::query()->whereKey($req->id)->where('status', Req::STATUS_PENDING)
                ->update(['status' => Req::STATUS_RUNNING, 'started_at' => now()]);
            if (! $claimed) {
                continue;
            }
            $this->process($req->fresh());
            $this->heartbeat();
            $handled++;
        }

        try {
            $this->syncActiveLetsEncrypt();
        } catch (\Throwable $e) {
            report($e);
        }

        return ['handled' => $handled];
    }

    public function heartbeat(): void
    {
        Setting::put(PlatformHttps::HEARTBEAT, [
            'last_seen' => now()->toIso8601String(),
            'version' => self::VERSION,
            'certbot' => is_executable((string) config('domaindash.https.commands.certbot')),
            'nginx' => is_executable((string) config('domaindash.https.commands.nginx')),
            'uid' => function_exists('posix_geteuid') ? posix_geteuid() : null,
        ]);
    }

    /** Renewal timer entry point. */
    public function renew(): array
    {
        $this->heartbeat();
        $cfg = config('domaindash.https');
        $req = Req::create(['type' => 'renew', 'status' => Req::STATUS_RUNNING, 'started_at' => now()]);

        $ok = false;
        $message = '';
        try {
            if (! is_executable($cfg['commands']['certbot'])) {
                throw new RuntimeException('certbot is not installed at ' . $cfg['commands']['certbot']);
            }
            $result = Process::timeout((int) $cfg['certbot_timeout'])->run([
                $cfg['commands']['certbot'], 'renew', '--quiet', '--non-interactive',
                '--deploy-hook', $cfg['paths']['deploy_hook'],
            ]);
            $message = LogSanitizer::clean($result->output() . "\n" . $result->errorOutput());
            $ok = $result->successful();
            if (! $ok) {
                $message = 'certbot renew exited with status ' . $result->exitCode() . "\n" . $message;
            }
            $this->syncActiveLetsEncrypt();
        } catch (\Throwable $e) {
            $message = LogSanitizer::clean($e->getMessage());
        }

        $req->update([
            'status' => $ok ? Req::STATUS_SUCCEEDED : Req::STATUS_FAILED,
            'log' => $message ?: ($ok ? 'Renewal check completed.' : null),
            'error' => $ok ? null : substr(strtok($message, "\n") ?: 'renewal failed', 0, 500),
            'finished_at' => now(),
        ]);
        Setting::put(PlatformHttps::LAST_RENEWAL, [
            'at' => now()->toIso8601String(), 'ok' => $ok, 'message' => substr($message, -1000), 'notified' => false,
        ]);
        AuditLogger::logSystem($ok ? 'https.renew' : 'https.renew.failed', $ok ? 'Certificate renewal check completed.' : 'Certificate renewal failed.', [
            'service' => 'platform-https', 'request_id' => $req->id,
        ]);

        return ['ok' => $ok];
    }

    // ---------------------------------------------------------------- requests

    private function process(Req $req): void
    {
        $log = [];
        try {
            match ($req->type) {
                'letsencrypt' => $this->issueLetsEncrypt($req, $log),
                'upload' => $this->installUpload($req, $log),
                default => throw new RuntimeException('Unsupported request type.'), // allowlist
            };
            $this->finish($req, true, $log);
        } catch (\Throwable $e) {
            $log[] = 'ERROR: ' . $e->getMessage();
            $this->finish($req, false, $log, $e->getMessage());
        }
    }

    private function finish(Req $req, bool $ok, array $log, ?string $error = null): void
    {
        $req->update([
            'status' => $ok ? Req::STATUS_SUCCEEDED : Req::STATUS_FAILED,
            'log' => LogSanitizer::clean(implode("\n", $log)),
            'error' => $error ? substr(LogSanitizer::clean($error, 480), 0, 500) : null,
            'payload' => null, // key material never lingers
            'finished_at' => now(),
        ]);
        AuditLogger::logSystem($ok ? 'https.request.succeeded' : 'https.request.failed',
            sprintf('Platform HTTPS %s request #%d %s.', $req->type, $req->id, $ok ? 'succeeded' : 'failed'), [
                'service' => 'platform-https', 'request_id' => $req->id, 'hostname' => $req->hostname,
            ]);
    }

    private function failStaleRunning(): void
    {
        Req::query()->where('status', Req::STATUS_RUNNING)
            ->where('started_at', '<', now()->subMinutes(self::STALE_RUNNING_MINUTES))
            ->update(['status' => Req::STATUS_FAILED, 'error' => 'Timed out (helper stopped while running).', 'finished_at' => now(), 'payload' => null]);
    }

    private function issueLetsEncrypt(Req $req, array &$log): void
    {
        $cfg = config('domaindash.https');

        try {
            $host = HostnameValidator::hostname((string) $req->hostname, true); // wildcard OK: DNS-01
            $email = HostnameValidator::email((string) $req->email);
        } catch (\InvalidArgumentException $e) {
            throw new RuntimeException('Request rejected: ' . $e->getMessage());
        }
        $staging = (bool) $req->staging;
        $certName = HostnameValidator::certName($host);
        $log[] = "Requesting a " . ($staging ? 'STAGING ' : '') . "Let's Encrypt certificate for {$host} (DNS-01).";

        // Pre-flight: zone managed in Synergy + credentials work.
        $zone = $this->zones->find($host);
        if ($zone === null) {
            throw new RuntimeException("DNS for {$host} is not managed in Synergy (no matching domain in the account). Upload a certificate instead.");
        }
        try {
            $this->synergy->balanceQuery();
        } catch (\Throwable $e) {
            throw new RuntimeException('Synergy credentials check failed: ' . $e->getMessage());
        }
        $log[] = "Zone {$zone} found in Synergy; API credentials OK.";
        $ns = $this->resolver->lookupNs($zone);
        if ($ns && ! collect($ns)->contains(fn ($n) => str_ends_with($n, '.nameserver.net.au'))) {
            $log[] = 'WARNING: ' . $zone . ' does not appear to use Synergy nameservers (' . implode(', ', array_slice($ns, 0, 4)) . '). The DNS-01 challenge may not be visible to Let\'s Encrypt.';
        }

        if (! is_executable($cfg['commands']['certbot'])) {
            throw new RuntimeException('certbot is not installed at ' . $cfg['commands']['certbot']);
        }

        $cmd = [
            $cfg['commands']['certbot'], 'certonly', '--manual', '--preferred-challenges', 'dns',
            '--manual-auth-hook', $cfg['paths']['auth_hook'],
            '--manual-cleanup-hook', $cfg['paths']['cleanup_hook'],
            '-d', $host, '--cert-name', $certName,
            '--non-interactive', '--agree-tos', '-m', $email,
            '--deploy-hook', $cfg['paths']['deploy_hook'],
        ];
        if ($staging) {
            $cmd[] = '--staging';
        }

        $result = Process::timeout((int) $cfg['certbot_timeout'])->run($cmd);
        $log[] = LogSanitizer::clean($result->output() . "\n" . $result->errorOutput(), 4000);
        if (! $result->successful()) {
            throw new RuntimeException('certbot failed (exit ' . $result->exitCode() . ').');
        }

        $live = rtrim($cfg['paths']['letsencrypt_live'], '/') . '/' . $certName;
        $fullchain = $live . '/fullchain.pem';
        $key = $live . '/privkey.pem';
        $certPem = (string) @file_get_contents($live . '/cert.pem');
        $meta = $this->validator->inspect($certPem);
        if (! HostnameValidator::covers($host, $meta['sans'])) {
            throw new RuntimeException('Issued certificate does not cover the requested hostname.');
        }

        $this->activate($fullchain, $key, null, $log);
        $this->recordActive($req, $host, 'letsencrypt', $meta, $staging, true);
        $log[] = 'Certificate installed and nginx reloaded.';
    }

    private function installUpload(Req $req, array &$log): void
    {
        $cfg = config('domaindash.https');
        try {
            $host = HostnameValidator::hostname((string) $req->hostname, true);
            $payload = json_decode(Crypt::decryptString((string) $req->payload), true, 4, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new RuntimeException('Upload payload is missing or could not be decrypted.');
        }

        try {
            // Re-validate everything: do not trust that the web app did.
            $bundle = $this->validator->validatePem(
                (string) ($payload['cert'] ?? ''), (string) ($payload['key'] ?? ''), (string) ($payload['chain'] ?? ''), $host
            );
        } catch (CertificateException $e) {
            throw new RuntimeException('Certificate rejected: ' . $e->getMessage());
        }
        $log[] = 'Uploaded certificate re-validated (key match, expiry, hostname, chain order).';

        $dir = rtrim($cfg['paths']['ssl_dir'], '/') . '/' . HostnameValidator::certName($host);
        $this->ensureDir($cfg['paths']['ssl_dir'], 0755);
        $this->ensureDir($dir, 0750);
        $this->writeAtomic($dir . '/fullchain.pem', $bundle->fullchainPem(), 0644);
        $this->writeAtomic($dir . '/privkey.pem', $bundle->privateKeyPem, 0600);

        $stapling = $bundle->meta['ocsp'] !== null && $bundle->chainPem !== '';
        if ($stapling) {
            $this->writeAtomic($dir . '/chain.pem', $bundle->chainPem, 0644);
        }

        $this->activate($dir . '/fullchain.pem', $dir . '/privkey.pem', $stapling ? $dir . '/chain.pem' : null, $log);
        $this->recordActive($req, $host, 'upload', $bundle->meta, false, false, $bundle);
        $log[] = 'Certificate installed and nginx reloaded.';
    }

    private function recordActive(Req $req, string $host, string $source, array $meta, bool $staging, bool $autoRenew, $bundle = null): void
    {
        PlatformCertificate::query()->where('is_active', true)->update(['is_active' => false]);
        PlatformCertificate::create([
            'hostname' => $host, 'source' => $source,
            'issuer' => $meta['issuer'], 'subject' => $meta['subject'], 'sans' => $meta['sans'],
            'fingerprint_sha256' => $meta['fingerprint_sha256'], 'key_type' => $meta['key_type'],
            'not_before' => $meta['not_before'], 'not_after' => $meta['not_after'],
            'is_active' => true, 'auto_renew' => $autoRenew, 'staging' => $staging,
            'last_renewed_at' => $source === 'letsencrypt' ? now() : null,
            'certificate_pem' => $bundle?->certificatePem,
            'chain_pem' => $bundle?->chainPem,
            'private_key_encrypted' => $bundle ? Crypt::encryptString($bundle->privateKeyPem) : null,
            'request_id' => $req->id,
        ]);
    }

    /** Detect certbot renewals (new fingerprint) and refresh the stored metadata. */
    public function syncActiveLetsEncrypt(): void
    {
        $cert = PlatformCertificate::query()->where('is_active', true)->where('source', 'letsencrypt')->latest('id')->first();
        if (! $cert) {
            return;
        }
        $pem = @file_get_contents(rtrim(config('domaindash.https.paths.letsencrypt_live'), '/') . '/' . HostnameValidator::certName($cert->hostname) . '/cert.pem');
        if (! is_string($pem) || $pem === '') {
            return;
        }
        $meta = $this->validator->inspect($pem);
        if ($meta['fingerprint_sha256'] === $cert->fingerprint_sha256) {
            return;
        }
        $cert->update([
            'issuer' => $meta['issuer'], 'subject' => $meta['subject'], 'sans' => $meta['sans'],
            'fingerprint_sha256' => $meta['fingerprint_sha256'], 'key_type' => $meta['key_type'],
            'not_before' => $meta['not_before'], 'not_after' => $meta['not_after'], 'last_renewed_at' => now(),
        ]);
        AuditLogger::logSystem('https.renewed', 'Platform certificate renewed by certbot.', [
            'service' => 'platform-https', 'hostname' => $cert->hostname, 'not_after' => $meta['not_after']->toDateString(),
        ]);
    }

    // ------------------------------------------------------------------ nginx

    /**
     * Write the nginx SSL snippet, run nginx -t, reload. On any failure the previous snippet
     * is restored (or removed if there was none) and an exception is thrown.
     */
    public function activate(string $fullchain, string $key, ?string $trustedChain, array &$log): void
    {
        $cfg = config('domaindash.https');
        $snippet = $cfg['paths']['ssl_snippet'];
        $previous = is_file($snippet) ? file_get_contents($snippet) : null;

        $this->writeAtomic($snippet, $this->renderSnippet($fullchain, $key, $trustedChain), 0644);

        $test = Process::timeout(30)->run([$cfg['commands']['nginx'], '-t']);
        if (! $test->successful()) {
            $this->restoreSnippet($snippet, $previous);
            $log[] = 'nginx -t failed: ' . LogSanitizer::clean($test->errorOutput() . $test->output(), 1500);
            throw new RuntimeException('nginx configuration test failed; previous configuration restored.');
        }

        $reload = Process::timeout(60)->run([$cfg['commands']['systemctl'], 'reload', 'nginx']);
        if (! $reload->successful()) {
            $this->restoreSnippet($snippet, $previous);
            $log[] = 'systemctl reload nginx failed: ' . LogSanitizer::clean($reload->errorOutput(), 800);
            throw new RuntimeException('nginx reload failed; previous configuration restored.');
        }
    }

    public function renderSnippet(string $fullchain, string $key, ?string $trustedChain): string
    {
        foreach ([$fullchain, $key, $trustedChain] as $p) {
            // Defence in depth: paths end up in a config file.
            if ($p !== null && ! preg_match('#^/[A-Za-z0-9._/*-]+$#', $p)) {
                throw new RuntimeException('Refusing unsafe path in nginx snippet.');
            }
        }

        $lines = [
            '# Managed by DomainDash (domaindash:certctl). Do not edit: changes are overwritten.',
            "ssl_certificate {$fullchain};",
            "ssl_certificate_key {$key};",
            '',
            'ssl_protocols TLSv1.2 TLSv1.3;',
            'ssl_prefer_server_ciphers off;',
            'ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305;',
            'ssl_session_timeout 1d;',
            'ssl_session_cache shared:DomainDashSSL:10m;',
            'ssl_session_tickets off;',
        ];
        if ($trustedChain !== null) {
            // Only for uploaded certs that publish an OCSP responder (Let\'s Encrypt no longer does).
            array_push($lines, '', 'ssl_stapling on;', 'ssl_stapling_verify on;', "ssl_trusted_certificate {$trustedChain};", 'resolver 1.1.1.1 9.9.9.9 valid=300s;', 'resolver_timeout 5s;');
        }

        return implode("\n", $lines) . "\n";
    }

    private function restoreSnippet(string $path, ?string $previous): void
    {
        if ($previous === null) {
            @unlink($path);
        } else {
            $this->writeAtomic($path, $previous, 0644);
        }
    }

    // ------------------------------------------------------------------- fs

    private function ensureDir(string $dir, int $mode): void
    {
        if (is_link($dir)) {
            throw new RuntimeException("Refusing to use symlinked directory {$dir}.");
        }
        if (! is_dir($dir) && ! @mkdir($dir, $mode, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create {$dir}.");
        }
        @chmod($dir, $mode);
    }

    private function writeAtomic(string $path, string $content, int $mode): void
    {
        $tmp = $path . '.tmp' . bin2hex(random_bytes(4));
        $old = umask(0077);
        try {
            if (file_put_contents($tmp, $content) === false) {
                throw new RuntimeException("Cannot write {$path}.");
            }
            chmod($tmp, $mode);
            if (! rename($tmp, $path)) {
                throw new RuntimeException("Cannot replace {$path}.");
            }
        } finally {
            umask($old);
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }
}
