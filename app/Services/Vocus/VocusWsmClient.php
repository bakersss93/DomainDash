<?php

namespace App\Services\Vocus;

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

    /** Short, user-safe messages for the documented basic error codes (Main doc 5.1). */
    const FRIENDLY_ERRORS = [
        'WSM-4005' => 'Login required (session expired).',
        'WSM-4020' => 'The Vocus WSM account is blocked. Contact Vocus.',
        'WSM-4050' => 'Insufficient access privileges for this product or operation.',
        'WSM-4080' => 'Vocus request rate limit exceeded. Try again in a few seconds.',
        'WSM-5010' => 'The operation was only partially completed. Check the transaction and open a Vocus support ticket.',
        'WSM-5030' => 'A Vocus supporting system reported an error. Open a Vocus support ticket.',
    ];

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

        if (! is_file($path) || ! is_readable($path)) {
            throw new \RuntimeException('The configured Vocus CA bundle is not readable: ' . $path);
        }

        return $path;
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

        $ch = curl_init($loginUrl);
        curl_setopt_array($ch, $curlOptions);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new \RuntimeException("Vocus WSM login cURL error: {$curlError}");
        }

        if ($httpCode !== 202) {
            throw new \RuntimeException(
                "Vocus WSM login failed - HTTP {$httpCode}. Check the certificate, login URL, and that this server's IP "
                . 'address is on the Vocus allow-list (a maximum of 2 client IPs is permitted).'
            );
        }

        if (preg_match('/Set-Cookie:\s*JSESSIONID=([^;\s]+)/i', $response, $matches)) {
            return $matches[1];
        }

        throw new \RuntimeException('Vocus WSM login succeeded but no JSESSIONID cookie was returned.');
    }

    protected function resolveCertPath(): string
    {
        $certPath = $this->settings()['cert_path'] ?? null;

        if (!$certPath) {
            throw new \RuntimeException('Vocus client certificate path is not configured. Please upload a certificate in Settings.');
        }

        if (!Storage::disk('local')->exists($certPath)) {
            $full = Storage::disk('local')->path($certPath);
            throw new \RuntimeException("Vocus client certificate not found at: {$full}");
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

        // Legacy location inside the vendor documentation folder.
        $legacy = base_path('vocus-api/Vocus-Wholesale/Schemas/WholesaleServiceManagement.wsdl');
        if (file_exists($legacy)) {
            return $legacy;
        }

        throw new \RuntimeException('Vocus WSDL not found. Expected at: ' . $bundled);
    }

    protected function resolveCertType(string $certPath): string
    {
        $ext = strtolower(pathinfo($certPath, PATHINFO_EXTENSION));

        if (in_array($ext, ['pem', 'crt', 'cer', 'key'])) {
            return 'PEM';
        }

        // .keystore may be JKS or PKCS12 — detect by magic bytes.
        // JKS starts with 0xFEEDFEED; PKCS12 starts with 0x3082 (ASN.1 SEQUENCE).
        if ($ext === 'keystore') {
            $handle = fopen($certPath, 'rb');
            $magic  = $handle ? bin2hex(fread($handle, 4)) : '';
            if ($handle) {
                fclose($handle);
            }
            if ($magic === 'feedfeed') {
                throw new \RuntimeException(
                    'The uploaded certificate is in Java JKS format, which is not supported by cURL. ' .
                    'Please convert it to PKCS#12 (.p12) format using: ' .
                    'keytool -importkeystore -srckeystore client.keystore -destkeystore client.p12 -deststoretype PKCS12'
                );
            }
            // Modern Java keystores default to PKCS12 — treat as P12.
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

        return new SoapClient($wsdlPath, $options);
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

            try {
                $result = $this->soap()->__soapCall($operation, [$soapVar]);

                return $this->parseResponse($result);
            } catch (SoapFault $e) {
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

                Log::error('Vocus WSM SOAP fault', [
                    'operation'     => $operation,
                    'productId'     => $productId,
                    'errorCode'     => $fault['code'],
                    'transactionId' => $fault['transaction_id'],
                    'message'       => $e->getMessage(),
                ]);

                $friendly = self::FRIENDLY_ERRORS[$fault['code']] ?? $e->getMessage();
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

        $xml .= '<AccessKey>' . $this->e($this->config['access_key'] ?? '') . '</AccessKey>';

        if (!empty($this->config['alias_key'])) {
            $xml .= '<AliasKey>' . $this->e($this->config['alias_key']) . '</AliasKey>';
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

    /**
     * Search for NBN-enabled addresses via Vocus DIR service.
     * Returns list of ['directory_id', 'carrier', 'address_long'].
     */
    public function lookupAddress(array $addressParts): array
    {
        $params = array_merge($addressParts, ['SearchMode' => 'DEFAULT']);
        $result = $this->call('Get', 'DIR', $params, 'BROADBAND');

        $addresses = [];
        $raw = $result['params']['FibreAddressRecord'] ?? [];
        if (!is_array($raw)) {
            $raw = [$raw];
        }

        foreach ($raw as $cdata) {
            if (!$cdata) {
                continue;
            }
            // Strip CDATA wrapper if present
            $cdata = preg_replace('/^<!\[CDATA\[|\]\]>$/', '', trim($cdata));
            $xml = @simplexml_load_string($cdata);
            if ($xml) {
                $addresses[] = [
                    'directory_id' => (string) ($xml->DirectoryID ?? ''),
                    'carrier'      => (string) ($xml->Carrier ?? ''),
                    'address_long' => (string) ($xml->AddressLong ?? ''),
                ];
            }
        }

        return [
            'transaction_id' => $result['transaction_id'],
            'addresses'      => $addresses,
        ];
    }

    /**
     * Service qualification against an NBN address.
     */
    public function qualifyAddress(string $directoryId, ?string $avcId = null, ?string $cvcId = null): array
    {
        $params = ['DirectoryID' => $directoryId];
        if ($avcId) {
            $params['AVCID'] = $avcId;
        }
        if ($cvcId) {
            $params['CVCID'] = $cvcId;
        }

        $result = $this->call('Get', 'FIBRE', $params, null, 'QUALIFY');

        // Parse any embedded CopperPairRecord or NBNPortRecord CDATA
        $parsed = $result['params'];
        foreach (['CopperPairRecord', 'NBNPortRecord'] as $key) {
            if (isset($parsed[$key])) {
                $records = is_array($parsed[$key]) ? $parsed[$key] : [$parsed[$key]];
                $parsedRecords = [];
                foreach ($records as $cdata) {
                    $cdata = preg_replace('/^<!\[CDATA\[|\]\]>$/', '', trim($cdata));
                    $xml = @simplexml_load_string($cdata);
                    if ($xml) {
                        $parsedRecords[] = json_decode(json_encode($xml), true);
                    }
                }
                $parsed[$key] = $parsedRecords;
            }
        }

        return array_merge($result, ['params' => $parsed]);
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
     * Retrieve NBN event notifications starting from a record cursor.
     * StartRecordID=0 returns from the beginning of the notification log.
     */
    public function getNotifications(int $startRecordId = 0): array
    {
        return $this->call('Get', 'FIBRE', ['StartRecordID' => (string) $startRecordId], null, 'NOTIFICATIONS');
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
     * Retrieve authentication log for a service (last 48 hours).
     */
    public function getAuthLog(string $serviceId, string $planId): array
    {
        $result = $this->call('Get', 'OPER', [
            'ServiceID' => $serviceId,
            'ProductID' => 'FIBRE',
        ], $planId, 'AUTH-LOG');

        // Parse AuthUsageRecord CDATA entries
        $records = [];
        $raw = $result['params']['AuthUsageRecord'] ?? [];
        if (!is_array($raw)) {
            $raw = [$raw];
        }
        foreach ($raw as $cdata) {
            if (!$cdata) {
                continue;
            }
            $cdata = preg_replace('/^<!\[CDATA\[|\]\]>$/', '', trim($cdata));
            $xml = @simplexml_load_string($cdata);
            if ($xml) {
                $records[] = [
                    'datetime'    => (string) ($xml->DateTime ?? ''),
                    'auth_result' => (string) ($xml->AuthResult ?? ''),
                    'reason'      => (string) ($xml->Reason ?? ''),
                ];
            }
        }

        return [
            'transaction_id' => $result['transaction_id'],
            'records'        => $records,
        ];
    }

    /**
     * Disconnect the current online session for a service.
     */
    public function disconnectSession(string $serviceId, string $planId): array
    {
        return $this->call('Set', 'OPER', [
            'ServiceID' => $serviceId,
            'ProductID' => 'FIBRE',
        ], $planId, 'DISCONNECT');
    }
}
