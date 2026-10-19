<?php

namespace App\Services\Https;

use App\Models\PlatformAcmeChallenge;
use App\Models\PlatformCertificate;
use App\Models\PlatformCertificateRequest;
use App\Services\Synergy\SynergyWholesaleClient;
use App\Support\Https\HostnameValidator;
use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * certbot --manual-auth-hook / --manual-cleanup-hook implementation (DNS-01 via Synergy).
 * Inputs come from certbot's environment (CERTBOT_DOMAIN, CERTBOT_VALIDATION) and are
 * validated strictly; the domain must also belong to a request/certificate we manage.
 */
class AcmeDnsHook
{
    private Closure $sleeper;

    public function __construct(
        private SynergyWholesaleClient $synergy,
        private TxtResolver $resolver,
        ?Closure $sleeper = null,
        private ?int $timeout = null,
        private ?int $interval = null,
        private ?int $settle = null,
    ) {
        $this->sleeper = $sleeper ?? static fn (int $s) => sleep($s);
        $this->timeout ??= (int) config('domaindash.https.dns_timeout', 180);
        $this->interval ??= (int) config('domaindash.https.dns_poll_interval', 10);
        $this->settle ??= (int) config('domaindash.https.dns_settle_seconds', 15);
    }

    /** @return string log line */
    public function auth(string $domain, string $validation): string
    {
        [$domain, $validation] = $this->validate($domain, $validation);
        $zone = (new ZoneFinder($this->synergy))->find($domain);
        if ($zone === null) {
            throw new RuntimeException("DNS for {$domain} is not managed in Synergy; cannot complete the DNS-01 challenge.");
        }

        $fqdn = '_acme-challenge.' . $domain;
        $recordName = $this->relativeName($fqdn, $zone);
        $ttl = (int) config('domaindash.https.dns_ttl', 300);

        $res = $this->synergy->addDNSRecord($zone, $recordName, 'TXT', $validation, $ttl);
        $recordId = isset($res['id']) ? (string) $res['id'] : $this->findRecordId($zone, $fqdn, $validation);

        PlatformAcmeChallenge::create([
            'domain' => $domain, 'zone' => $zone, 'record_name' => $recordName,
            'value' => $validation, 'record_id' => $recordId,
        ]);

        $elapsed = 0;
        while (! in_array($validation, $this->resolver->lookupTxt($fqdn), true)) {
            if ($elapsed >= $this->timeout) {
                $this->cleanup($domain, $validation); // do not leave the record behind
                throw new RuntimeException("TXT record for {$fqdn} did not become visible within {$this->timeout}s.");
            }
            ($this->sleeper)($this->interval);
            $elapsed += $this->interval;
        }

        if ($this->settle > 0) {
            ($this->sleeper)($this->settle);
        }

        return "TXT {$fqdn} created in zone {$zone} and visible after {$elapsed}s.";
    }

    /** Removes only the record created for this value. Idempotent. */
    public function cleanup(string $domain, string $validation): string
    {
        [$domain, $validation] = $this->validate($domain, $validation);

        $rows = PlatformAcmeChallenge::query()->where('domain', $domain)->where('value', $validation)->get();
        $removed = 0;

        if ($rows->isEmpty()) {
            $zone = (new ZoneFinder($this->synergy))->find($domain);
            if ($zone !== null && ($id = $this->findRecordId($zone, '_acme-challenge.' . $domain, $validation)) !== null) {
                $this->synergy->deleteDNSRecord($zone, $id);
                $removed++;
            }
        }

        foreach ($rows as $row) {
            $id = $row->record_id ?: $this->findRecordId($row->zone, '_acme-challenge.' . $domain, $validation);
            if ($id !== null) {
                $this->synergy->deleteDNSRecord($row->zone, $id);
                $removed++;
            }
            $row->delete();
        }

        return "Removed {$removed} TXT record(s) for _acme-challenge.{$domain}.";
    }

    /** @return array{0:string,1:string} */
    public function validate(string $domain, string $validation): array
    {
        try {
            $domain = HostnameValidator::hostname($domain); // certbot strips "*." already
            $validation = HostnameValidator::acmeValidation($validation);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException('Rejected certbot input: ' . $e->getMessage());
        }

        if (! $this->isAuthorized($domain)) {
            throw new RuntimeException("Rejected certbot input: {$domain} is not a hostname requested through DomainDash.");
        }

        return [$domain, $validation];
    }

    private function isAuthorized(string $domain): bool
    {
        $hosts = PlatformCertificateRequest::query()
            ->where('status', PlatformCertificateRequest::STATUS_RUNNING)
            ->whereNotNull('hostname')->pluck('hostname')
            ->merge(PlatformCertificate::query()->where('source', 'letsencrypt')->pluck('hostname'))
            ->map(fn ($h) => str_starts_with($h, '*.') ? substr($h, 2) : $h);

        return $hosts->contains($domain);
    }

    private function relativeName(string $fqdn, string $zone): string
    {
        return $fqdn === $zone ? '' : substr($fqdn, 0, -strlen('.' . $zone));
    }

    private function findRecordId(string $zone, string $fqdn, string $value): ?string
    {
        $relative = $this->relativeName($fqdn, $zone);
        foreach ($this->synergy->listDNSZone($zone) as $r) {
            $r = (array) $r;
            $host = strtolower(rtrim((string) ($r['hostName'] ?? ''), '.'));
            $content = trim((string) ($r['content'] ?? ''), "\" \t");
            if (strtoupper((string) ($r['type'] ?? '')) === 'TXT'
                && in_array($host, [$fqdn, $relative], true)
                && $content === $value
                && ! empty($r['id'])) {
                return (string) $r['id'];
            }
        }

        return null;
    }
}
