<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformCertificate;
use App\Models\PlatformCertificateRequest;
use App\Services\AuditLogger;
use App\Services\Https\PlatformHttps;
use App\Support\Https\CertificateException;
use App\Support\Https\CertificateValidator;
use App\Support\Https\HostnameValidator;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Admin-only (route group: auth + verified + mfa.policy + role:Administrator).
 * The web process only validates input and writes DB rows; the root helper does the work.
 */
class PlatformHttpsController extends Controller
{
    public function __construct(private PlatformHttps $https, private CertificateValidator $validator)
    {
    }

    public function index()
    {
        $cert = PlatformCertificate::active();
        $heartbeat = $this->https->heartbeat();
        $open = $this->https->hasOpenRequest();

        return view('admin.settings.https', [
            'cert' => $cert,
            'heartbeat' => $heartbeat,
            // A long-running request legitimately delays the heartbeat; do not nag then.
            'helperWarning' => $heartbeat['stale'] && ! $open,
            'requests' => PlatformCertificateRequest::query()->latest('id')->limit(25)->get(),
            'defaultHost' => $this->https->hostFromAppUrl(),
            'force' => PlatformHttps::forceEnabled(),
            'canForce' => $this->https->hasValidActiveCertificate(),
            'renewal' => $this->https->lastRenewal(),
            'hasOpen' => $open,
            'currentlySecure' => request()->isSecure(),
        ]);
    }

    public function letsEncrypt(Request $request)
    {
        $request->merge([
            'hostname' => HostnameValidator::normalize((string) $request->input('hostname')),
            'email' => trim((string) $request->input('email')),
        ]);
        $data = $request->validate([
            'hostname' => ['required', 'string', 'max:253'],
            'email' => ['required', 'string', 'max:254'],
            'staging' => ['nullable', 'boolean'],
        ]);

        try {
            HostnameValidator::hostname($data['hostname'], true);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['hostname' => $e->getMessage()]);
        }
        try {
            HostnameValidator::email($data['email']);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['email' => $e->getMessage()]);
        }

        if ($this->https->hasOpenRequest()) {
            throw ValidationException::withMessages(['hostname' => 'Another certificate request is still in progress.']);
        }
        if ($this->https->managedZone($data['hostname']) === null) {
            throw ValidationException::withMessages(['hostname' => "DNS for {$data['hostname']} is not managed in Synergy (no matching domain in DomainDash). Let's Encrypt DNS validation needs Synergy DNS; upload a certificate instead."]);
        }

        $req = $this->https->createLetsEncryptRequest($data['hostname'], $data['email'], (bool) ($data['staging'] ?? false), $request->user()?->id);
        AuditLogger::logSystem('https.request.letsencrypt', "Let's Encrypt certificate requested for {$data['hostname']}.", [
            'service' => 'platform-https', 'request_id' => $req->id, 'hostname' => $data['hostname'], 'staging' => (bool) ($data['staging'] ?? false),
        ]);

        return redirect()->route('admin.https')->with('status', 'Certificate request queued. The helper picks it up within a minute.');
    }

    public function upload(Request $request)
    {
        $request->merge(['hostname' => HostnameValidator::normalize((string) $request->input('hostname'))]);
        $max = (int) config('domaindash.https.upload_max_kb', 64);
        $data = $request->validate([
            'hostname' => ['required', 'string', 'max:253'],
            'mode' => ['required', 'in:pem,pfx'],
            'certificate' => ['nullable', 'string', 'max:100000'],
            'certificate_file' => ['nullable', 'file', "max:{$max}"],
            'private_key' => ['nullable', 'string', 'max:20000'],
            'private_key_file' => ['nullable', 'file', "max:{$max}"],
            'chain' => ['nullable', 'string', 'max:100000'],
            'chain_file' => ['nullable', 'file', "max:{$max}"],
            'pfx_file' => ['nullable', 'file', "max:{$max}"],
            'pfx_password' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            HostnameValidator::hostname($data['hostname'], true);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['hostname' => $e->getMessage()]);
        }
        if ($this->https->hasOpenRequest()) {
            throw ValidationException::withMessages(['hostname' => 'Another certificate request is still in progress.']);
        }

        $read = fn (string $text, string $file) => $request->hasFile($file) ? (string) file_get_contents($request->file($file)->getRealPath()) : (string) ($data[$text] ?? '');

        try {
            if ($data['mode'] === 'pfx') {
                if (! $request->hasFile('pfx_file')) {
                    throw new CertificateException('Choose a PFX/P12 file.');
                }
                $bundle = $this->validator->validatePfx($read('', 'pfx_file'), (string) ($data['pfx_password'] ?? ''), $data['hostname']);
            } else {
                $cert = $read('certificate', 'certificate_file');
                $key = $read('private_key', 'private_key_file');
                if (trim($cert) === '' || trim($key) === '') {
                    throw new CertificateException('Provide both the certificate and the private key.');
                }
                $bundle = $this->validator->validatePem($cert, $key, $read('chain', 'chain_file'), $data['hostname']);
            }
        } catch (CertificateException $e) {
            throw ValidationException::withMessages(['certificate' => $e->getMessage()]);
        }

        $req = $this->https->createUploadRequest($data['hostname'], $bundle, $request->user()?->id);
        AuditLogger::logSystem('https.request.upload', "Certificate uploaded for {$data['hostname']} ({$data['mode']}).", [
            'service' => 'platform-https', 'request_id' => $req->id, 'hostname' => $data['hostname'],
            'fingerprint_sha256' => $bundle->meta['fingerprint_sha256'], 'not_after' => $bundle->meta['not_after']->toDateString(),
        ]);

        return redirect()->route('admin.https')->with('status', 'Certificate validated and queued for installation.');
    }

    public function force(Request $request)
    {
        $data = $request->validate(['force' => ['required', 'boolean'], 'confirm' => ['nullable', 'boolean']]);
        $on = (bool) $data['force'];

        if ($on) {
            if (! $this->https->hasValidActiveCertificate()) {
                throw ValidationException::withMessages(['force' => 'Force HTTPS can only be enabled while a valid certificate is active.']);
            }
            if (! ($data['confirm'] ?? false)) {
                throw ValidationException::withMessages(['force' => 'Confirm that you have checked the site loads over https:// first.']);
            }
        }

        PlatformHttps::setForce($on);
        AuditLogger::logSystem($on ? 'https.force.enabled' : 'https.force.disabled', 'Force HTTPS ' . ($on ? 'enabled' : 'disabled') . '.', ['service' => 'platform-https']);

        return redirect()->route('admin.https')->with('status', 'Force HTTPS ' . ($on ? 'enabled.' : 'disabled.'));
    }
}
