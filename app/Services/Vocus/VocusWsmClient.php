<?php

namespace App\Services\Vocus;

use App\Casts\EncryptedSettingValue;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use SoapClient;
use SoapFault;
use SoapVar;

class VocusWsmClient
{
    protected ?object $soap = null;
    protected ?array $config = null;
    protected ?VocusRateLimiter $limiter = null;

    /** @var callable|null fn(string $wsdl, array $options, string $sessionId): object */
    protected $soapFactory;

    /** @var callable|null fn(): string  returns a fresh JSESSIONID (tests / custom auth) */
    protected $loginCallback;

    // Namespace constants
    const NS_WSM = 'https://wsm.webservice.m2.com.au/schemas/WholesaleServiceManagement.xsd';
    const NS_STD = 'https://wsm.webservice.m2.com.au/schemas/StandardDataTypes.xsd';
    const DEFAULT_WSDL_URL = 'https://wsm.webservice.m2.com.au/WholesaleServiceManagement';
    const DEFAULT_LOGIN_URL = 'https://wsm.webservice.m2.com.au:9443/login/';

    /** Exact wording required when no Access Key is available to send. */
    const ACCESS_KEY_MISSING = 'Vocus Access Key is not configured (or settings could not be decrypted — was APP_KEY changed?)';

    /**
     * Short, user-safe messages for the documented error codes (Main doc 5.1 and
     * the TCAS catalogue). The vendor says faultstring "is less reliable", so the
     * ErrorCode drives the message. WSM-4010 is NOT in the supplied documents; its
     * text is inferred from production behaviour (rejected Access Key). The FIBRE-, DIR-, OPER- and NETWORK- codes
     * are from the product catalogues (FIBRE 3, DIR 3, OPER 3, NETWORK 3).
     */
    const FRIENDLY_ERRORS = [
        'WSM-0000' => 'Vocus reported an internal error. Try again later or open a Vocus support ticket.',
        'WSM-4000' => 'Vocus rejected the request (general client error).',
        'WSM-4005' => 'Login required (session expired).',
        'WSM-4010' => 'Access key rejected: check the Vocus Access Key in Settings (inferred; this code is not documented by Vocus).',
        'WSM-4020' => 'The Vocus WSM account is blocked. Contact Vocus.',
        'WSM-4050' => 'Insufficient access privileges for this product or operation.',
        'WSM-4080' => 'Vocus request rate limit exceeded. Try again in a few seconds.',
        'WSM-4220' => 'Vocus rejected the request as invalid (XML validation error).',
        'WSM-4221' => 'Invalid Vocus ProductID.',
        'WSM-4224' => 'Invalid Vocus operation.',
        'WSM-5000' => 'Vocus reported a general server error. Try again later or open a Vocus support ticket.',
        'WSM-5010' => 'The operation was only partially completed. Check the transaction and open a Vocus support ticket.',
        'WSM-5030' => 'A Vocus supporting system reported an error. Open a Vocus support ticket.',
        'WSM-5050' => 'This Vocus operation is not implemented.',
        'TCAS-0000' => 'Vocus transaction lookup failed with an internal error.',
        'TCAS-4220' => 'Vocus rejected the transaction lookup parameters.',
        'TCAS-4222' => 'Invalid Vocus transaction lookup PlanID.',
        'TCAS-4223' => 'The Vocus transaction lookup is missing its scope.',
        'TCAS-4300' => 'Vocus does not know this transaction ID.',
        'TCAS-4310' => 'This Vocus transaction has expired (transactions are kept for 7 days after completion). Refresh the live service record instead.',
        'TCAS-5030' => 'A Vocus supporting system failed while looking up the transaction. Open a Vocus support ticket.',
        'TCAS-5050' => 'The Vocus transaction lookup is not implemented.',
        // Product Catalogue FIBRE section 3.
        'FIBRE-0000' => 'Vocus reported an internal error on the NBN service. Try again later or open a Vocus support ticket.',
        'FIBRE-2110' => 'Vocus could not provision the NBN service.',
        'FIBRE-2111' => 'Vocus does not know this service ID (invalid or unknown service).',
        'FIBRE-2115' => 'This service ID already exists at Vocus.',
        'FIBRE-2118' => 'The NBN appointment is not valid or does not exist.',
        'FIBRE-2170' => 'That plan change is not valid.',
        'FIBRE-2200' => 'The service is in a stage where it cannot be modified.',
        'FIBRE-2205' => 'Vocus could not update the service status.',
        'FIBRE-2210' => 'A modem or other CPE cannot be added at this stage of the service.',
        'FIBRE-4220' => 'Vocus rejected the NBN request: a parameter is invalid.',
        'FIBRE-4222' => 'Vocus does not know this plan ID.',
        'FIBRE-4223' => 'The NBN request scope is missing or invalid.',
        'FIBRE-5010' => 'The NBN operation was only partially completed. Check the transaction steps and open a Vocus support ticket.',
        'FIBRE-5030' => 'A Vocus supporting system reported an error. Open a Vocus support ticket with the transaction ID.',
        'FIBRE-5050' => 'This NBN feature is not implemented by Vocus yet.',
        'FIBRE-5555' => 'Vocus does not support this action for this service.',
        // Product Catalogue DIR section 3.
        'DIR-0000' => 'Vocus reported an internal error in the address directory.',
        'DIR-1181' => 'Vocus did not find that address.',
        'DIR-2000' => 'That Vocus DirectoryID does not exist.',
        'DIR-4220' => 'Vocus rejected the address parameters.',
        'DIR-4222' => 'Invalid Vocus directory PlanID.',
        'DIR-4223' => 'The Vocus directory request scope is invalid.',
        'DIR-5030' => 'A Vocus supporting system failed during the address lookup. Open a Vocus support ticket.',
        'DIR-5050' => 'This Vocus directory feature is not implemented.',
        // Product Catalogue OPER section 3.
        'OPER-0000' => 'Vocus reported an internal error running the diagnostic.',
        'OPER-2111' => 'Vocus does not know this service (invalid or unknown service).',
        'OPER-2200' => 'Vocus could not validate the service credentials or status for this diagnostic.',
        'OPER-4220' => 'Vocus rejected the diagnostic parameters.',
        'OPER-4222' => 'The diagnostic plan ID does not exist at Vocus.',
        'OPER-4223' => 'The diagnostic scope is missing or invalid.',
        'OPER-5010' => 'The diagnostic was only partially completed. Open a Vocus support ticket.',
        'OPER-5030' => 'A Vocus supporting system failed during the diagnostic. Open a Vocus support ticket.',
        'OPER-5050' => 'This diagnostic is not implemented.',
        'OPER-5555' => 'This diagnostic is not supported for this service.',
        // Product Catalogue NETWORK section 3.
        'NETWORK-0000' => 'Vocus reported an internal error on the network request.',
        'NETWORK-2110' => 'Vocus could not provision the network item.',
        'NETWORK-2111' => 'Vocus does not know this CVC or network item.',
        'NETWORK-4220' => 'Vocus rejected the network request: a parameter is invalid.',
        'NETWORK-4222' => 'Invalid Vocus network PlanID.',
        'NETWORK-4223' => 'The network request scope is missing or invalid.',
        'NETWORK-5555' => 'Vocus does not support this network action.',
    ];

    /** Codes whose own faultstring is informative (it names what was invalid), so it is appended. */
    const RAW_DETAIL_CODES = ['WSM-4000', 'WSM-4220', 'TCAS-4220', 'FIBRE-2110', 'FIBRE-2170', 'FIBRE-4220', 'DIR-4220', 'OPER-4220', 'NETWORK-4220'];

    /**
     * Nothing touches the network here: the session login and SOAP client are
     * created lazily on the first operation.
     *
     * @param callable|null $soapFactory   fn(string $wsdl, array $options, string $sessionId): object (SoapClient-like)
     * @param array|null    $config        Vocus settings (defaults to Setting 'vocus')
     * @param callable|null $loginCallback fn(): string session id (defaults to the certificate login)
     */
    public function __construct(?callable $soapFactory = null, ?array $config = null, ?callable $loginCallback = null)
    {
        $this->soapFactory   = $soapFactory;
        $this->config        = $config;
        $this->loginCallback = $loginCallback;
    }

    public function setRateLimiter(VocusRateLimiter $limiter): static
    {
        $this->limiter = $limiter;

        return $this;
    }

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    protected function settings(): array
    {
        if ($this->config === null) {
            $this->config = (array) Setting::get('vocus', [
                'access_key'    => null,
                'alias_key'     => null,
                'cert_path'     => null,
                'cert_password' => null,
            ]);
        }

        return $this->config;
    }

    /**
     * Human reason why Vocus cannot be called, or null when the minimum
     * (Access Key + client certificate) is configured. Separates "never
     * configured" from "saved but unreadable" (APP_KEY changed), like
     * HaloPsaClient::configurationProblem().
     */
    public static function configurationProblem(?array $settings = null): ?string
    {
        $settings ??= (array) Setting::get('vocus', []);

        if (trim((string) ($settings['access_key'] ?? '')) === '') {
            return ! empty(EncryptedSettingValue::$decryptFailures['vocus'])
                ? 'The Vocus settings could not be decrypted (was APP_KEY changed?). Re-enter the Vocus Access Key, alias key and certificate password in Settings → Vocus Wholesale.'
                : 'Vocus Access Key is not configured. Enter the Access Key and upload the client certificate in Settings → Vocus Wholesale.';
        }

        if (empty($settings['cert_path'])) {
            return 'The Vocus client certificate has not been uploaded. Upload it in Settings → Vocus Wholesale.';
        }

        return null;
    }

    public static function isConfigured(): bool
    {
        return self::configurationProblem() === null;
    }

    /** production|sandbox */
    public function environment(): string
    {
        $env = strtolower((string) ($this->settings()['environment'] ?? config('services.vocus.environment', 'production')));

        return $env === 'sandbox' ? 'sandbox' : 'production';
    }

    protected function endpointFor(string $key, string $legacyKey, string $default): string
    {
        $settings = $this->settings();
        $env = $this->environment();

        // Explicit per-install override wins. The legacy `wsdl_url`/`login_url`
        // settings only apply in production so they cannot pin a sandbox switch.
        $explicit = $settings[$key . '_override'] ?? ($key === 'endpoint' ? ($settings['endpoint_url'] ?? null) : ($settings['login_url_override'] ?? null));
        if (! empty($explicit)) {
            return (string) $explicit;
        }

        if ($env === 'production' && ! empty($settings[$legacyKey])) {
            return (string) $settings[$legacyKey];
        }

        return (string) (config("services.vocus.{$env}.{$key}") ?: $default);
    }

    public function endpointUrl(): string
    {
        return $this->endpointFor('endpoint', 'wsdl_url', self::DEFAULT_WSDL_URL);
    }

    public function loginUrl(): string
    {
        return $this->endpointFor('login_url', 'login_url', self::DEFAULT_LOGIN_URL);
    }

    /**
     * Optional PEM CA bundle / pinned certificate (needed for the sandbox's
     * self-signed certificate). Verification itself is never disabled.
     */
    protected function caBundle(): ?string
    {
        $path = $this->settings()['ca_bundle_path'] ?? config('services.vocus.ca_bundle');

        if (empty($path)) {
            return null;
        }

        // Uploaded in Settings: stored relative to the local disk (vocus/ca.pem).
        if (! str_starts_with((string) $path, '/') && Storage::disk('local')->exists($path)) {
            $path = Storage::disk('local')->path($path);
        }

        if (! is_file($path) || ! is_readable($path)) {
            Log::warning('The configured Vocus CA bundle is not readable', ['path' => $path]);
            throw new VocusConfigurationException('The configured Vocus CA bundle is not readable by the application.', null, 'LOCAL-LOGIN');
        }

        return $path;
    }

    /**
     * IP the WSM hostname is pinned to, or null for normal DNS. The sandbox is
     * only reachable at an unadvertised IP (Sandbox guide 2.1); pinning avoids
     * editing the server's hosts file. Production always uses DNS.
     */
    public function pinnedIp(): ?string
    {
        if ($this->environment() !== 'sandbox') {
            return null;
        }

        $s = $this->settings();
        if (array_key_exists('sandbox_pin_ip', $s) && ! filter_var($s['sandbox_pin_ip'], FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        $ip = trim((string) ($s['sandbox_ip'] ?? config('services.vocus.sandbox.ip', '')));

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }

    /** CURLOPT_RESOLVE entry for a URL when an IP is pinned. */
    protected function resolveEntry(string $url): ?string
    {
        $ip = $this->pinnedIp();
        if ($ip === null) {
            return null;
        }

        $parts = parse_url($url);
        $host = $parts['host'] ?? null;
        if (! $host) {
            return null;
        }
        $port = $parts['port'] ?? (($parts['scheme'] ?? 'https') === 'https' ? 443 : 80);

        return "{$host}:{$port}:{$ip}";
    }

    protected function connectionTimeout(): int
    {
        return (int) config('services.vocus.connection_timeout', 10);
    }

    protected function timeout(): int
    {
        return (int) config('services.vocus.timeout', 30);
    }

    protected function limiter(): VocusRateLimiter
    {
        return $this->limiter ??= new VocusRateLimiter(
            (int) config('services.vocus.rate_limit.max_requests', 4),
            (int) config('services.vocus.rate_limit.per_seconds', 10),
            (int) config('services.vocus.rate_limit.max_wait_seconds', 15),
        );
    }

    // -------------------------------------------------------------------------
    // Session management
    // -------------------------------------------------------------------------

    /** Cache key scoped to environment, endpoint, certificate and access key. */
    protected function sessionCacheKey(): string
    {
        $s = $this->settings();

        return 'vocus_wsm_session:' . sha1(implode('|', [
            $this->environment(),
            $this->loginUrl(),
            $s['cert_path'] ?? '',
            $s['access_key'] ?? '',
        ]));
    }

    protected function getOrCreateSession(): string
    {
        $key = $this->sessionCacheKey();

        if ($cached = Cache::get($key)) {
            return $cached;
        }

        // Single login even when several workers start at once.
        return Cache::lock($key . ':login', 40)->block(40, function () use ($key) {
            return Cache::remember($key, (int) config('services.vocus.session_ttl', 540), fn () => $this->login());
        });
    }

    protected function forgetSession(): void
    {
        Cache::forget($this->sessionCacheKey());
        $this->soap = null;
    }

    protected function login(): string
    {
        if ($this->loginCallback) {
            return (string) ($this->loginCallback)();
        }

        $certPath = $this->resolveCertPath();
        $certPassword = $this->settings()['cert_password'] ?? '';
        $loginUrl = $this->loginUrl();

        $certType = $this->resolveCertType($certPath);

        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_NOBODY         => false,
            CURLOPT_SSLCERT        => $certPath,
            CURLOPT_SSLCERTPASSWD  => $certPassword,
            CURLOPT_SSLCERTTYPE    => $certType,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => $this->connectionTimeout(),
            CURLOPT_TIMEOUT        => max(30, $this->timeout()),
        ];

        if ($ca = $this->caBundle()) {
            $curlOptions[CURLOPT_CAINFO] = $ca;
        }

        if ($resolve = $this->resolveEntry($loginUrl)) {
            $curlOptions[CURLOPT_RESOLVE] = [$resolve];
        }

        $ch = curl_init($loginUrl);
        curl_setopt_array($ch, $curlOptions);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            Log::warning('Vocus WSM login cURL error', ['curl_error' => $this->scrub($curlError)]);

            throw new VocusConfigurationException(
                'Vocus WSM login could not connect: ' . $this->scrub($curlError)
                . ' (a legacy Java-exported PKCS#12 certificate may need the OpenSSL legacy provider).',
                null,
                'LOCAL-LOGIN'
            );
        }

        if ($httpCode !== 202) {
            throw new VocusConfigurationException(
                "Vocus WSM login failed - HTTP {$httpCode}. Check the certificate, login URL, and that this server's IP "
                . 'address is on the Vocus allow-list (a maximum of 2 client IPs is permitted).',
                null,
                'LOCAL-LOGIN'
            );
        }

        if (preg_match('/Set-Cookie:\s*JSESSIONID=([^;\s]+)/i', (string) $response, $matches)) {
            return $matches[1];
        }

        throw new VocusConfigurationException('Vocus WSM login succeeded but no JSESSIONID cookie was returned.', null, 'LOCAL-LOGIN');
    }

    protected function resolveCertPath(): string
    {
        $certPath = $this->settings()['cert_path'] ?? null;

        if (!$certPath) {
            throw new VocusConfigurationException('Vocus client certificate is not configured. Please upload a certificate in Settings → Vocus Wholesale.');
        }

        if (!Storage::disk('local')->exists($certPath)) {
            // The server path stays in the log; the UI message never exposes it.
            Log::warning('Vocus client certificate file is missing', ['path' => Storage::disk('local')->path($certPath)]);
            throw new VocusConfigurationException('The stored Vocus client certificate file is missing. Upload the certificate again in Settings → Vocus Wholesale.');
        }

        return Storage::disk('local')->path($certPath);
    }

    protected function resolveWsdlPath(): string
    {
        // Prefer a WSDL stored in the local disk (uploaded or overridden).
        if (Storage::disk('local')->exists('wsdl/vocus.wsdl')) {
            return Storage::disk('local')->path('wsdl/vocus.wsdl');
        }

        // Bundled copy (WSDL + the two XSDs it imports) shipped with the application.
        $bundled = base_path('resources/wsdl/vocus/WholesaleServiceManagement.wsdl');
        if (file_exists($bundled)) {
            return $bundled;
        }

        throw new \RuntimeException('The bundled Vocus WSDL (resources/wsdl/vocus/WholesaleServiceManagement.wsdl) is missing.');
    }

    public const JKS_MESSAGE = 'The uploaded certificate is in Java JKS format, which is not supported by cURL. '
        . 'Convert it to PKCS#12 (.p12) with: keytool -importkeystore -srckeystore client.keystore -destkeystore client.p12 -deststoretype PKCS12';

    /** True when the file starts with the Java KeyStore magic number 0xFEEDFEED. */
    public static function isJavaKeystore(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if (! $handle) {
            return false;
        }
        $magic = bin2hex((string) fread($handle, 4));
        fclose($handle);

        return $magic === 'feedfeed';
    }

    protected function resolveCertType(string $certPath): string
    {
        $ext = strtolower(pathinfo($certPath, PATHINFO_EXTENSION));

        if (in_array($ext, ['pem', 'crt', 'cer', 'key'])) {
            return 'PEM';
        }

        // JKS (0xFEEDFEED) is detected by magic bytes whatever the extension is;
        // PKCS12 starts with 0x3082 (ASN.1 SEQUENCE). Modern Java keystores
        // default to PKCS12 — treat as P12.
        if (self::isJavaKeystore($certPath)) {
            throw new VocusConfigurationException(self::JKS_MESSAGE, null, 'LOCAL-LOGIN');
        }

        return 'P12';
    }

    protected function buildSoapClient(string $sessionId): object
    {
        $wsdlPath = $this->resolveWsdlPath();
        $endpoint = $this->endpointUrl();

        $ssl = [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ];
        if ($ca = $this->caBundle()) {
            $ssl['cafile'] = $ca;
        }

        $context = stream_context_create([
            'http' => [
                'header'  => "Cookie: JSESSIONID={$sessionId}\r\n",
                'timeout' => (float) $this->timeout(),
            ],
            'ssl' => $ssl,
        ]);

        $options = [
            'trace'              => false, // trace would retain request XML (incl. AccessKey) in memory
            'exceptions'         => true,
            'cache_wsdl'         => 1, // WSDL_CACHE_DISK (constant only exists with ext-soap)
            'connection_timeout' => $this->connectionTimeout(),
            'stream_context'     => $context,
            'location'           => $endpoint,
        ];

        if ($this->soapFactory) {
            return ($this->soapFactory)($wsdlPath, $options, $sessionId);
        }

        if (! class_exists(SoapClient::class)) {
            throw new \RuntimeException('The PHP soap extension (ext-soap) is not installed; cannot call Vocus WSM.');
        }

        return (new VocusSoapClient($wsdlPath, $options))->configureTransport(
            $sessionId,
            $this->pinnedIp(),
            $this->caBundle(),
            $this->connectionTimeout(),
            $this->timeout(),
        );
    }

    protected function soap(): object
    {
        return $this->soap ??= $this->buildSoapClient($this->getOrCreateSession());
    }

    // -------------------------------------------------------------------------
    // Core SOAP dispatch
    // -------------------------------------------------------------------------

    protected function call(string $operation, string $productId, array $params = [], ?string $planId = null, ?string $scope = null): array
    {
        $xml = $this->buildRequestXml($operation, $productId, $params, $planId, $scope);
        $soapVar = new SoapVar($xml, XSD_ANYXML);

        $retriedSession = false;
        $retriedRate    = false;

        while (true) {
            $this->limiter()->acquire();

            $previousTimeout = ini_get('default_socket_timeout');
            @ini_set('default_socket_timeout', (string) max(1, $this->timeout()));

            $startedAt = microtime(true);

            try {
                $result = $this->soap()->__soapCall($operation, [$soapVar]);
                $this->recordCall($operation, $xml, $productId, $params, $result, null, $startedAt);

                return $this->parseResponse($result);
            } catch (SoapFault $e) {
                $this->recordCall($operation, $xml, $productId, $params, null, $e, $startedAt);
                $fault = $this->extractFault($e);

                // Session expired - drop it, log in again and retry once.
                if ($fault['code'] === 'WSM-4005' && ! $retriedSession) {
                    $retriedSession = true;
                    $this->forgetSession();
                    continue;
                }

                // Flooded: Vocus blocks the client for ~10s. Wait it out and retry once.
                if ($fault['code'] === 'WSM-4080' && ! $retriedRate) {
                    $retriedRate = true;
                    $this->limiter()->sleep(10);
                    continue;
                }

                $rawMessage = $this->scrub($e->getMessage());

                Log::error('Vocus WSM SOAP fault', [
                    'operation'     => $operation,
                    'productId'     => $productId,
                    'errorCode'     => $fault['code'],
                    'transactionId' => $fault['transaction_id'],
                    'message'       => $rawMessage,
                ]);

                $friendly = self::FRIENDLY_ERRORS[$fault['code'] ?? ''] ?? $rawMessage;
                if (isset(self::FRIENDLY_ERRORS[$fault['code'] ?? '']) && in_array($fault['code'], self::RAW_DETAIL_CODES, true) && $rawMessage !== '') {
                    $friendly .= ' Vocus said: ' . $rawMessage;
                }
                $label    = $fault['code'] ?? 'no error code';
                $suffix   = $fault['transaction_id'] ? " [TransactionID {$fault['transaction_id']}]" : '';

                throw new VocusWsmException(
                    "Vocus WSM error ({$label}): {$friendly}{$suffix}",
                    $fault['code'],
                    $fault['transaction_id'],
                    $e
                );
            } finally {
                if ($previousTimeout !== false) {
                    @ini_set('default_socket_timeout', (string) $previousTimeout);
                }
            }
        }
    }

    /**
     * Monitoring: record one WSM call (request XML redacted by the logger). Never throws.
     */
    protected function recordCall(string $operation, string $xml, string $productId, array $params, mixed $result, ?\Throwable $fault, float $startedAt): void
    {
        try {
            $reference = null;
            foreach (['ServiceID', 'ServiceId', 'serviceId'] as $field) {
                if (isset($params[$field]) && is_scalar($params[$field])) {
                    $reference = (string) $params[$field];
                    break;
                }
            }

            app(\App\Services\Monitoring\IntegrationRequestLogger::class)->logSoap(
                'vocus',
                $operation,
                $this->endpointUrl(),
                $xml,
                $result,
                $fault,
                (int) round((microtime(true) - $startedAt) * 1000),
                $reference ?? $productId,
                $reference !== null ? 'internet_service' : 'product',
                is_object($result) ? (string) ($result->ResponseType ?? '') ?: null : null,
            );
        } catch (\Throwable) {
            // monitoring must never break the WSM call
        }
    }

    /**
     * Pull ErrorCode / TransactionID out of a SoapFault.
     *
     * Documented fault detail is <detail><FaultResponse><TransactionID/><ErrorCode/>
     * </FaultResponse></detail> (sandbox doc; the main doc names the wrapper
     * "Fault"), so the code is one level below $e->detail. We search the whole
     * detail tree so either wrapper (or none) works.
     *
     * @return array{code:?string,transaction_id:?string}
     */
    public function extractFault(SoapFault $e): array
    {
        $found = ['code' => null, 'transaction_id' => null];
        $this->scanFaultNode($e->detail ?? null, $found);

        return $found;
    }

    protected function scanFaultNode(mixed $node, array &$found): void
    {
        if (is_string($node)) {
            if ($found['code'] === null && preg_match('/<(?:\w+:)?ErrorCode>\s*([^<\s]+)\s*</i', $node, $m)) {
                $found['code'] = $m[1];
            }
            if ($found['transaction_id'] === null && preg_match('/<(?:\w+:)?TransactionID>\s*([^<\s]+)\s*</i', $node, $m)) {
                $found['transaction_id'] = $m[1];
            }

            return;
        }

        if (! is_object($node) && ! is_array($node)) {
            return;
        }

        foreach ((array) $node as $key => $value) {
            $name = strtolower((string) preg_replace('/^.*[:\\\\]/', '', (string) $key));

            if (($name === 'errorcode' || $name === 'transactionid') && (is_scalar($value) || (is_object($value) && isset($value->_)))) {
                $scalar = is_object($value) ? (string) $value->_ : (string) $value;
                $slot = $name === 'errorcode' ? 'code' : 'transaction_id';
                if ($found[$slot] === null && $scalar !== '') {
                    $found[$slot] = trim($scalar);
                }
                continue;
            }

            $this->scanFaultNode($value, $found);
        }
    }

    /**
     * Remove every configured secret (Access Key, Alias Key, certificate
     * password) from text that may be logged, thrown or shown.
     */
    protected function scrub(string $text): string
    {
        $settings = $this->settings();
        foreach (['access_key', 'alias_key', 'cert_password'] as $field) {
            $secret = (string) ($settings[$field] ?? '');
            if (strlen($secret) >= 4) {
                $text = str_replace($secret, '[REDACTED]', $text);
            }
        }

        return $text;
    }

    /** @param array<string,mixed> $values */
    protected function scrubArray(array $values): array
    {
        return array_map(fn ($v) => is_string($v) ? $this->scrub($v) : $v, $values);
    }

    protected function buildRequestXml(string $operation, string $productId, array $params, ?string $planId, ?string $scope): string
    {
        $elementName = ucfirst(strtolower($operation)) . 'Request';

        // Declare both namespaces at the root. The WSM namespace covers the
        // request envelope and its direct children (AccessKey, ProductID, etc.).
        // Parameters/Param are defined in StandardDataTypes (std:) and must
        // carry that namespace — otherwise the server rejects with an
        // "unexpected element" unmarshalling error.
        $xml  = "<{$elementName} xmlns=\"" . self::NS_WSM . '"';
        $xml .= ' xmlns:std="' . self::NS_STD . '">';

        // settings() loads the stored config on first use; reading $this->config
        // directly here sent an empty AccessKey on a fresh client (WSM-4010).
        $settings = $this->settings();

        // Never send an empty key: Vocus answers with the confusing WSM-4010. Fail
        // here, before any login or SOAP call, with a message that says what to fix.
        $accessKey = trim((string) ($settings['access_key'] ?? ''));
        if ($accessKey === '') {
            $hint = ! empty(EncryptedSettingValue::$decryptFailures['vocus'])
                ? ' The stored Vocus settings could not be decrypted; re-enter them in Settings → Vocus Wholesale.'
                : ' Enter it in Settings → Vocus Wholesale.';

            throw new VocusConfigurationException(self::ACCESS_KEY_MISSING . '.' . $hint);
        }

        $xml .= '<AccessKey>' . $this->e($accessKey) . '</AccessKey>';

        if (!empty($settings['alias_key'])) {
            $xml .= '<AliasKey>' . $this->e($settings['alias_key']) . '</AliasKey>';
        }

        $xml .= '<ProductID>' . $this->e($productId) . '</ProductID>';

        if ($planId !== null) {
            $xml .= '<PlanID>' . $this->e($planId) . '</PlanID>';
        }

        if ($scope !== null) {
            $xml .= '<Scope>' . $this->e($scope) . '</Scope>';
        }

        if (!empty($params)) {
            $xml .= '<Parameters>';
            foreach ($params as $id => $value) {
                $xml .= '<std:Param id="' . $this->e($id) . '">' . $this->e((string) $value) . '</std:Param>';
            }
            $xml .= '</Parameters>';
        }

        $xml .= "</{$elementName}>";

        return $xml;
    }

    protected function parseResponse(mixed $response): array
    {
        if (!is_object($response)) {
            return ['transaction_id' => null, 'response_type' => null, 'params' => []];
        }

        $transactionId = $response->TransactionID ?? null;
        $responseType  = $response->ResponseType ?? null;
        $params        = $this->extractParams($response->Parameters ?? null);

        return [
            'transaction_id' => $transactionId,
            'response_type'  => $responseType,
            'params'         => $params,
        ];
    }

    protected function extractParams(mixed $parameters): array
    {
        if ($parameters === null) {
            return [];
        }

        $paramList = $parameters->Param ?? null;
        if ($paramList === null) {
            return [];
        }

        if (!is_array($paramList)) {
            $paramList = [$paramList];
        }

        $result = [];
        foreach ($paramList as $param) {
            $id    = $param->id ?? null;
            $value = $param->_ ?? (is_string($param) ? $param : null);

            if ($id === null) {
                continue;
            }

            // Multiple params with same id become an array (e.g. FibreAddressRecord, Notes)
            if (array_key_exists($id, $result)) {
                if (!is_array($result[$id])) {
                    $result[$id] = [$result[$id]];
                }
                $result[$id][] = $value;
            } else {
                $result[$id] = $value;
            }
        }

        return $result;
    }

    protected function e(string $value): string
    {
        // Control characters are illegal in XML 1.0 and would break the request.
        $value = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value);

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    // -------------------------------------------------------------------------
    // Address / Directory operations
    // -------------------------------------------------------------------------

    /** Params that narrow a lookup to one building/unit; the street-level lookup omits them and narrows locally. */
    const LOOKUP_DETAIL_KEYS = [
        'Main.Unit1stNumber', 'Main.Unit1stSuffix', 'Main.Unit1stType', 'Main.Street1stNumber',
        'Main.Street1stNumberSuffix', 'Main.FloorType', 'Main.FloorNumber', 'Main.FloorSuffix', 'Main.LotNumber',
    ];

    /**
     * Every request param DIR::Get PlanID BROADBAND accepts (Product Catalogue DIR 5.2). Unlike DIR::Create it has
     * Main.Unit1stType (not Main.AddressType), no PropertyName / Unit2nd / Street2nd params and Main.Postcode with a
     * lower-case "c".
     */
    const BROADBAND_LOOKUP_PARAMS = [
        'Main.LotNumber', 'Main.Unit1stNumber', 'Main.Unit1stSuffix', 'Main.Unit1stType', 'Main.FloorNumber',
        'Main.FloorSuffix', 'Main.FloorType', 'Main.Street1stNumber', 'Main.Street1stNumberSuffix', 'Main.StreetName',
        'Main.StreetType', 'Main.StreetSuffix', 'Main.Suburb', 'Main.State', 'Main.Postcode',
    ];

    /**
     * Convert the address parser's DIR::Create style keys (Main.AddressType, Main.PostCode) to the documented
     * BROADBAND lookup keys and drop anything the lookup does not define.
     *
     * @param array<string,string|int> $params
     * @return array<string,string|int>
     */
    public static function broadbandLookupParams(array $params): array
    {
        if (isset($params['Main.AddressType']) && ! isset($params['Main.Unit1stType'])) {
            $params['Main.Unit1stType'] = $params['Main.AddressType'];
        }
        if (isset($params['Main.PostCode']) && ! isset($params['Main.Postcode'])) {
            $params['Main.Postcode'] = $params['Main.PostCode'];
        }

        return array_intersect_key($params, array_flip(self::BROADBAND_LOOKUP_PARAMS));
    }

    /**
     * One DIR::Get / PlanID BROADBAND call (Examples 4.1.1) and the parsed records.
     * Returns ['transaction_id', 'addresses', 'diagnostics'].
     */
    public function lookupAddress(array $addressParts): array
    {
        // SearchMode=DEFAULT is sent by the Examples lookup sample (4.1.1) although the DIR catalogue (5.2) does not
        // list it; it is the only SearchMode value seen anywhere and is kept as the sample has it.
        $params = array_merge(self::broadbandLookupParams($addressParts), ['SearchMode' => 'DEFAULT']);
        $result = $this->call('Get', 'DIR', $params, 'BROADBAND');

        [$addresses, $diagnostics] = self::parseAddressRecords($result['params']);

        // Sanitised request/response summary (never the AccessKey) so "no addresses found" can be diagnosed.
        Log::info('Vocus address lookup', [
            'sent'           => $this->scrubArray($params),
            'transaction_id' => $result['transaction_id'],
            'returned_param_ids' => array_keys($result['params']),
            'records'        => count($addresses),
            'record_parse_failures' => $diagnostics['unparsed'],
        ]);

        return [
            'transaction_id' => $result['transaction_id'],
            'addresses'      => $addresses,
            'diagnostics'    => $diagnostics,
            'sent'           => $params,
        ];
    }

    /**
     * Address search used by the UI. Starts with the request exactly as documented
     * (street name/type, suburb, state, postcode, no street number: the sample
     * response lists every unit of the street) and narrows locally by the
     * requested number; only when that finds nothing it retries with the street
     * number/unit sent and without the postcode (Main.Postcode is optional, DIR 5.2). Each attempt is logged. A Vocus fault is raised only when no
     * attempt succeeded.
     *
     * @param array<string,string|int> $params Main.* parameters (Main.PostCode spelling)
     * @return array{transaction_id:?string,addresses:array,attempts:array,refined:bool}
     */
    public function findAddresses(array $params): array
    {
        $params  = self::broadbandLookupParams($params);
        $details = array_intersect_key($params, array_flip(self::LOOKUP_DETAIL_KEYS));
        $base    = array_diff_key($params, $details);
        $post    = $base['Main.Postcode'] ?? null;
        unset($base['Main.Postcode']);

        $with = fn (array $p) => $post !== null && $post !== '' ? $p + ['Main.Postcode' => $post] : $p;

        $variants = [['documented request (street level)', $with($base)]];
        if ($details !== []) {
            $variants[] = ['with street number / unit', $with($base + $details)];
        }
        if ($post !== null && $post !== '') {
            $variants[] = ['without postcode', $base];
        }

        $attempts = [];
        $lastFault = null;
        $addresses = [];
        $transactionId = null;
        $anySucceeded = false;

        foreach ($variants as [$label, $sent]) {
            try {
                $r = $this->lookupAddress($sent);
            } catch (VocusWsmException $e) {
                if ($e->isAccountLevel() || $e->errorCode === 'LOCAL-RATE-LIMIT') {
                    throw $e;
                }
                $lastFault = $e;
                $attempts[] = ['variant' => $label, 'records' => 0, 'fault' => $e->errorCode];
                continue;
            }

            $anySucceeded = true;
            $attempts[] = ['variant' => $label, 'records' => count($r['addresses']), 'fault' => null];

            if ($r['addresses'] !== []) {
                $addresses = $r['addresses'];
                $transactionId = $r['transaction_id'];
                break;
            }
            $transactionId = $r['transaction_id'];
        }

        if (! $anySucceeded && $lastFault) {
            throw $lastFault;
        }

        $refined = false;
        if ($addresses !== [] && $details !== []) {
            $narrow = self::refineAddresses($addresses, $details);
            if ($narrow !== []) {
                $addresses = $narrow;
                $refined = true;
            }
        }

        return [
            'transaction_id' => $transactionId,
            'addresses'      => array_slice($addresses, 0, 100),
            'attempts'       => $attempts,
            'refined'        => $refined,
        ];
    }

    /**
     * Keep records whose AddressLong carries the requested street number
     * (and unit number) as whole words, e.g. "UNIT 2 99 EXAMPLE ST ..." for 2/99.
     *
     * @param array<int,array{directory_id:string,carrier:string,address_long:string}> $addresses
     */
    public static function refineAddresses(array $addresses, array $details): array
    {
        $street = isset($details['Main.Street1stNumber']) ? $details['Main.Street1stNumber'] . ($details['Main.Street1stNumberSuffix'] ?? '') : null;
        $unit   = isset($details['Main.Unit1stNumber']) ? $details['Main.Unit1stNumber'] . ($details['Main.Unit1stSuffix'] ?? '') : null;

        $has = static fn (string $haystack, string $needle) => (bool) preg_match('/(?<![A-Z0-9])' . preg_quote($needle, '/') . '(?![A-Z0-9])/', $haystack);

        return array_values(array_filter($addresses, static function ($a) use ($street, $unit, $has) {
            $long = strtoupper((string) $a['address_long']);

            return ($street === null || $has($long, strtoupper($street))) && ($unit === null || $has($long, strtoupper($unit)));
        }));
    }

    /**
     * Extract address records from DIR::Get parameters. Tolerates a single
     * record (not an array), any param id ending in "AddressRecord"
     * (FibreAddressRecord / BroadbandAddressRecord), padded values and, as a
     * fallback for malformed XML (e.g. an unescaped "&"), a regex read.
     *
     * @return array{0:array<int,array{directory_id:string,carrier:string,address_long:string}>,1:array{unparsed:int}}
     */
    public static function parseAddressRecords(array $params): array
    {
        $addresses = [];
        $unparsed = 0;

        foreach ($params as $id => $raw) {
            if (! preg_match('/AddressRecord$/i', (string) $id)) {
                continue;
            }

            foreach ((array) $raw as $cdata) {
                $cdata = trim((string) preg_replace('/^<!\[CDATA\[|\]\]>$/', '', trim((string) $cdata)));
                if ($cdata === '') {
                    continue;
                }

                $record = null;
                $xml = @simplexml_load_string($cdata);
                if ($xml !== false) {
                    $record = [
                        'directory_id' => trim((string) ($xml->DirectoryID ?? '')),
                        'carrier'      => trim((string) ($xml->Carrier ?? '')),
                        'address_long' => trim((string) preg_replace('/\s+/', ' ', (string) ($xml->AddressLong ?? ''))),
                    ];
                } elseif (preg_match('#<DirectoryID>\s*([^<\s]+)\s*</DirectoryID>#i', $cdata, $m)) {
                    preg_match('#<Carrier>\s*([^<]*?)\s*</Carrier>#i', $cdata, $c);
                    preg_match('#<AddressLong>(.*?)</AddressLong>#is', $cdata, $l);
                    $record = [
                        'directory_id' => $m[1],
                        'carrier'      => $c[1] ?? '',
                        'address_long' => trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($l[1] ?? '')))),
                    ];
                }

                if ($record !== null && $record['directory_id'] !== '') {
                    $addresses[] = $record;
                } else {
                    $unparsed++;
                }
            }
        }

        return [$addresses, ['unparsed' => $unparsed]];
    }

    /**
     * Service qualification against an NBN address.
     */
    public function qualifyAddress(string $directoryId, ?string $avcId = null, ?string $cvcId = null, ?string $carrierId = null, ?string $serviceType = null, ?string $productCode = null): array
    {
        // FIBRE catalogue 4.2.1. CarrierID: needed to check voiceband continuity on FTTB/N and required for FTTC.
        // ServiceType: qualify a specific technology (FTTP for Fibre On Demand / primary / original). ProductCode:
        // RESELLER-CONNECT (default), NETWORK-CONNECT, RESELLER-CONNECT-PLUS or NETWORK-CONNECT-PLUS.
        $params = ['DirectoryID' => $directoryId];
        if ($carrierId) {
            $params['CarrierID'] = $carrierId;
        }
        if ($serviceType) {
            $params['ServiceType'] = $serviceType;
        }
        if ($productCode) {
            $params['ProductCode'] = $productCode;
        }
        if ($avcId) {
            $params['AVCID'] = $avcId;
        }
        if ($cvcId) {
            $params['CVCID'] = $cvcId;
        }

        $result = $this->call('Get', 'FIBRE', $params, null, 'QUALIFY');

        // Normalised, technology-aware view (copper pairs / NTDs with ports). See VocusQualification.
        $qualification = VocusQualification::fromParams($result['params']);

        // Keep "params" backwards compatible: record params decoded to arrays of fields.
        $parsed = $result['params'];
        foreach (['CopperPairRecord', 'NTDRecord', 'NBNPortRecord'] as $key) {
            if (isset($parsed[$key])) {
                $parsed[$key] = VocusQualification::records($parsed[$key], $key);
            }
        }

        return array_merge($result, ['params' => $parsed, 'qualification' => $qualification]);
    }

    /**
     * Create a directory entry (customer address record) for CPE / NCD delivery.
     * Returns ['transaction_id', 'directory_id'].
     */
    public function createDirectoryEntry(array $contactDetails): array
    {
        $result = $this->call('Create', 'DIR', $contactDetails, 'STANDARD');

        return [
            'transaction_id' => $result['transaction_id'],
            'directory_id'   => $result['params']['DirectoryID'] ?? null,
        ];
    }

    // -------------------------------------------------------------------------
    // FIBRE (NBN) service operations
    // -------------------------------------------------------------------------

    /**
     * Get live details of an existing NBN service.
     */
    public function getService(string $serviceId): array
    {
        return $this->call('Get', 'FIBRE', ['ServiceID' => $serviceId], null, 'SERVICE');
    }

    /**
     * Order a new NBN service (NEW or CHURN/TRANSFER).
     * Returns ['transaction_id', 'response_type'].
     */
    public function orderService(string $planId, string $scope, array $params): array
    {
        return $this->call('Create', 'FIBRE', $params, $planId, $scope);
    }

    /**
     * Set service status: ACTIVE, SUSPEND, or INACTIVE (cancel).
     */
    public function setServiceStatus(string $serviceId, string $status): array
    {
        return $this->call('Set', 'FIBRE', [
            'ServiceID'     => $serviceId,
            'ServiceStatus' => $status,
        ], null, 'STATUS');
    }

    /**
     * FIBRE / Get / NOTIFICATIONS (Product Catalogue FIBRE 4.2.6): order and appointment updates up to 60 days old.
     * Send StartDateTime (AEST, YYYYMMDDHHMMSS) or StartRecordID (the LastRecordID of the previous call), plus an
     * optional MaxRecordCount (default 20, maximum 100). The response carries MasterConversationRecord and
     * MasterAppointmentRecord CDATA records, LastRecordID and HasMore. Not used by the application yet.
     * Note: ConversationRecord TransactionSubState COMPLETE does not mean the order is complete: only TCAS
     * scope RESPONSE returning SUCCESS does.
     *
     * @return array{transaction_id:?string,last_record_id:?string,has_more:bool,conversations:array,appointments:array,params:array}
     */
    public function getNotifications(?string $startDateTime = null, ?string $startRecordId = null, ?int $maxRecordCount = null): array
    {
        if (($startDateTime === null || $startDateTime === '') && ($startRecordId === null || $startRecordId === '')) {
            throw new \InvalidArgumentException('NOTIFICATIONS needs a StartDateTime or a StartRecordID.');
        }
        $params = array_filter([
            'StartDateTime'  => $startDateTime,
            'StartRecordID'  => $startRecordId,
            'MaxRecordCount' => $maxRecordCount !== null ? (string) max(1, min(100, $maxRecordCount)) : null,
        ], fn ($v) => $v !== null && $v !== '');

        $result = $this->call('Get', 'FIBRE', $params, null, 'NOTIFICATIONS');
        $p      = $result['params'];

        return [
            'transaction_id' => $result['transaction_id'],
            'last_record_id' => isset($p['LastRecordID']) ? (string) $p['LastRecordID'] : null,
            'has_more'       => strtoupper((string) ($p['HasMore'] ?? '')) === 'TRUE',
            'conversations'  => VocusQualification::records($p['MasterConversationRecord'] ?? null, 'MasterConversationRecord'),
            'appointments'   => VocusQualification::records($p['MasterAppointmentRecord'] ?? null, 'MasterAppointmentRecord'),
            'params'         => $p,
        ];
    }

    /**
     * NETWORK / Get / AGGCVC (Product Catalogue NETWORK 4.2.1): the Aggregated CVCs available to an NCL2 customer.
     * No param lists them all; CVCID returns one; State filters. Not wired into the UI yet.
     *
     * @return list<array<string,string>> CVCRecord fields
     */
    public function getAggregatedCvcs(?string $cvcId = null, ?string $state = null): array
    {
        $params = array_filter(['CVCID' => $cvcId, 'State' => $state], fn ($v) => $v !== null && $v !== '');
        $result = $this->call('Get', 'NETWORK', $params, null, 'AGGCVC');

        return VocusQualification::records($result['params']['CVCRecord'] ?? null, 'CVCRecord');
    }

    // -------------------------------------------------------------------------
    // TCAS polling
    // -------------------------------------------------------------------------

    /**
     * Poll the status of an async transaction by TransactionID.
     * TransactionState: QUEUED | PROCESSING | SUCCESS | FAILED | WITHDRAWN
     */
    public function pollTransaction(string $transactionId): array
    {
        return $this->call('Get', 'TCAS', ['TransactionID' => $transactionId], 'TRANSACTION-ID', 'RESPONSE');
    }

    /**
     * Normalise a pollTransaction() result: upper-case state (docs show mixed
     * case, e.g. "Processing"), final flag, and the TCAS faultcode/faultmessage
     * on FAILED transactions.
     *
     * @return array{state:?string,final:bool,fault_code:?string,fault_message:?string,params:array}
     */
    public static function normalizeTransactionResult(array $poll): array
    {
        $params = $poll['params'] ?? [];
        $state  = isset($params['TransactionState']) ? strtoupper(trim((string) $params['TransactionState'])) : null;

        return [
            'state'         => $state,
            'final'         => in_array($state, ['SUCCESS', 'FAILED', 'WITHDRAWN'], true),
            'fault_code'    => isset($params['faultcode']) ? (string) $params['faultcode'] : null,
            'fault_message' => isset($params['faultmessage']) ? (string) $params['faultmessage'] : null,
            'params'        => $params,
        ];
    }

    /**
     * Decide the local service_status after polling a transaction.
     *
     * SUCCESS only says the transaction completed - it may have been a
     * SUSPEND or INACTIVE (cancel) - so never assume ACTIVE. The status comes
     * from the live service record; without it nothing changes.
     */
    public static function resolveServiceStatus(?string $transactionState, ?string $liveServiceStatus): ?string
    {
        if (strtoupper((string) $transactionState) !== 'SUCCESS') {
            return null;
        }

        $live = strtoupper(trim((string) $liveServiceStatus));

        return $live !== '' ? $live : null;
    }

    // -------------------------------------------------------------------------
    // OPER diagnostics
    // -------------------------------------------------------------------------

    /**
     * OPER / Get / AUTH-LOG (Product Catalogue OPER 4.2.3): up to 2 days of authentication attempts. Supported for
     * FIBRE RESELLER-CONNECT only. "ProductID OPER will use the same PlanID as the product that will be manipulated"
     * (OPER 2.1), so PlanID is the service's own plan; ServiceID and ProductID (FIBRE) go in as params.
     * The catalogue names the response param AuthLogRecord, the Data Types and Examples AuthUsageRecord: both read.
     */
    public function getAuthLog(string $serviceId, string $planId): array
    {
        $result = $this->call('Get', 'OPER', [
            'ServiceID' => $serviceId,
            'ProductID' => 'FIBRE',
        ], $planId, 'AUTH-LOG');

        $records = [];
        foreach (['AuthLogRecord', 'AuthUsageRecord'] as $element) {
            foreach (VocusQualification::records($result['params'][$element] ?? null, $element) as $r) {
                $records[] = [
                    'datetime'    => $r['DateTime'] ?? '',
                    'auth_result' => $r['AuthResult'] ?? '',
                    'reason'      => $r['Reason'] ?? '',
                ];
            }
        }

        return [
            'transaction_id' => $result['transaction_id'],
            'records'        => $records,
        ];
    }

    /**
     * OPER / Set / DISCONNECT (OPER 4.3.1): kick the online session(s) of a FIBRE RESELLER-CONNECT service. Without a
     * SessionID every session of the ServiceID is disconnected; it can take up to 5 minutes. PlanID is the service's plan.
     */
    public function disconnectSession(string $serviceId, string $planId, ?string $sessionId = null): array
    {
        $params = ['ServiceID' => $serviceId, 'ProductID' => 'FIBRE'];
        if ($sessionId) {
            $params['SessionID'] = $sessionId;
        }

        return $this->call('Set', 'OPER', $params, $planId, 'DISCONNECT');
    }
}
