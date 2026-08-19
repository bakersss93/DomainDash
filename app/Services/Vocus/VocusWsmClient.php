<?php

namespace App\Services\Vocus;

use App\Models\Setting;
use SoapClient;
use SoapFault;
use SoapVar;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class VocusWsmClient
{
    protected SoapClient $soap;
    protected array $config;

    // Namespace constants
    const NS_WSM = 'https://wsm.webservice.m2.com.au/schemas/WholesaleServiceManagement.xsd';
    const NS_STD = 'https://wsm.webservice.m2.com.au/schemas/StandardDataTypes.xsd';
    const DEFAULT_WSDL_URL = 'https://wsm.webservice.m2.com.au/WholesaleServiceManagement';
    const DEFAULT_LOGIN_URL = 'https://wsm.webservice.m2.com.au:9443/login/';

    public function __construct()
    {
        $this->config = Setting::get('vocus', [
            'access_key'    => null,
            'alias_key'     => null,
            'wsdl_url'      => self::DEFAULT_WSDL_URL,
            'login_url'     => self::DEFAULT_LOGIN_URL,
            'cert_path'     => null,
            'cert_password' => null,
        ]);

        $sessionId = $this->getOrCreateSession();
        $this->soap = $this->buildSoapClient($sessionId);
    }

    // -------------------------------------------------------------------------
    // Session management
    // -------------------------------------------------------------------------

    protected function getOrCreateSession(): string
    {
        return Cache::remember('vocus_wsm_session', 540, fn () => $this->login());
    }

    protected function login(): string
    {
        $certPath = $this->resolveCertPath();
        $certPassword = $this->config['cert_password'] ?? '';
        $loginUrl = $this->config['login_url'] ?? self::DEFAULT_LOGIN_URL;

        $certType = $this->resolveCertType($certPath);

        $ch = curl_init($loginUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_NOBODY         => false,
            CURLOPT_SSLCERT        => $certPath,
            CURLOPT_SSLCERTPASSWD  => $certPassword,
            CURLOPT_SSLCERTTYPE    => $certType,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new \RuntimeException("Vocus WSM login cURL error: {$curlError}");
        }

        if ($httpCode !== 202) {
            throw new \RuntimeException("Vocus WSM login failed — HTTP {$httpCode}. Check certificate and login URL.");
        }

        if (preg_match('/Set-Cookie:\s*JSESSIONID=([^;\s]+)/i', $response, $matches)) {
            return $matches[1];
        }

        throw new \RuntimeException('Vocus WSM login succeeded but no JSESSIONID cookie was returned.');
    }

    protected function resolveCertPath(): string
    {
        $certPath = $this->config['cert_path'] ?? null;

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

        // Fall back to the bundled WSDL shipped with the application.
        $bundled = base_path('vocus-api/Vocus-Wholesale/Schemas/WholesaleServiceManagement.wsdl');
        if (file_exists($bundled)) {
            return $bundled;
        }

        throw new \RuntimeException('Vocus WSDL not found. Expected at: ' . Storage::disk('local')->path('wsdl/vocus.wsdl'));
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

    protected function buildSoapClient(string $sessionId): SoapClient
    {
        $wsdlPath = $this->resolveWsdlPath();
        $endpoint = $this->config['wsdl_url'] ?? self::DEFAULT_WSDL_URL;

        $context = stream_context_create([
            'http' => [
                'header' => "Cookie: JSESSIONID={$sessionId}\r\n",
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        return new SoapClient($wsdlPath, [
            'trace'          => 1,
            'exceptions'     => true,
            'cache_wsdl'     => WSDL_CACHE_MEMORY,
            'stream_context' => $context,
            'location'       => $endpoint,
        ]);
    }

    // -------------------------------------------------------------------------
    // Core SOAP dispatch
    // -------------------------------------------------------------------------

    protected function call(string $operation, string $productId, array $params = [], ?string $planId = null, ?string $scope = null): array
    {
        $xml = $this->buildRequestXml($operation, $productId, $params, $planId, $scope);
        $soapVar = new SoapVar($xml, XSD_ANYXML);

        try {
            $result = $this->soap->__soapCall($operation, [$soapVar]);
            return $this->parseResponse($result);
        } catch (SoapFault $e) {
            $errorCode = $this->extractSoapFaultCode($e);

            // Session expired — clear cache and retry once
            if ($errorCode === 'WSM-4005') {
                Cache::forget('vocus_wsm_session');
                $sessionId = $this->getOrCreateSession();
                $this->soap = $this->buildSoapClient($sessionId);

                $result = $this->soap->__soapCall($operation, [$soapVar]);
                return $this->parseResponse($result);
            }

            Log::error('Vocus WSM SOAP fault', [
                'operation'  => $operation,
                'productId'  => $productId,
                'errorCode'  => $errorCode,
                'message'    => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                "Vocus WSM error ({$errorCode}): {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    protected function extractSoapFaultCode(SoapFault $fault): ?string
    {
        $detail = $fault->detail ?? null;
        if (is_object($detail)) {
            $faultResponse = $detail->FaultResponse ?? $detail;
            if (is_object($faultResponse) && isset($faultResponse->ErrorCode)) {
                return (string) $faultResponse->ErrorCode;
            }
        }

        if (is_string($detail) && preg_match('/<ErrorCode>([^<]+)<\/ErrorCode>/i', $detail, $matches)) {
            return $matches[1];
        }

        return null;
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
     * Retrieve NBN order and appointment notifications for a requested timeframe.
     * The timestamp must use Vocus' YYYYMMDDHHMMSS format.
     */
    public function getNotifications(string $startDateTime): array
    {
        if (!preg_match('/^\d{14}$/', $startDateTime)) {
            throw new \InvalidArgumentException('Vocus notification start time must use YYYYMMDDHHMMSS format.');
        }

        return $this->call('Get', 'FIBRE', ['StartDateTime' => $startDateTime], null, 'NOTIFICATIONS');
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

    // -------------------------------------------------------------------------
    // OPER diagnostics
    // -------------------------------------------------------------------------

    /**
     * Retrieve authentication log for a service (last 48 hours).
     */
    public function getAuthLog(string $serviceId, string $serviceProductId = 'FIBRE'): array
    {
        $result = $this->call('Get', 'OPER', [
            'ServiceID' => $serviceId,
            'ProductID' => $serviceProductId,
        ], 'AUTH-LOG', 'USAGE-DAILY');

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
