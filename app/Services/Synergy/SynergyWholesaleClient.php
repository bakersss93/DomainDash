<?php

namespace App\Services\Synergy;

use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use SoapClient;
use SoapFault;

/**
 * Thin wrapper around the Synergy Wholesale SOAP API (v3.18).
 *
 * Every call goes through {@see self::invoke()}, which
 *  - injects reseller credentials,
 *  - applies connection/response timeouts,
 *  - wraps SoapFault in {@see SynergyApiException},
 *  - and throws when the response `status` is an error. Synergy reports API
 *    failures as a normal response with status=ERR_* (docs p.9), never as a
 *    SoapFault, so "no exception" used to be mistaken for success.
 *
 * The SoapClient is created lazily through an injectable factory so the class
 * can be unit tested without ext-soap or network access.
 */
class SynergyWholesaleClient
{
    /** Documented DNS record types (docs p.77). */
    public const DNS_RECORD_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT'];

    /** Max domains accepted by one bulkDomainInfo call. */
    public const BULK_CHUNK = 100;

    /** Default Synergy nameservers (dnsConfig 5, docs p.147). */
    public const DEFAULT_NAMESERVERS = ['ns1.nameserver.net.au', 'ns2.nameserver.net.au', 'ns3.nameserver.net.au'];

    /** @var object|null SoapClient or a test double exposing __soapCall() */
    protected ?object $soap = null;

    /** @var callable|null fn(string $wsdl, array $options): object */
    protected $soapFactory;

    protected ?array $credentials;
    protected ?array $config;
    protected ?string $wsdlOverride;

    /** @var array{at:int,payload:array}|null */
    protected ?array $sslCertCache = null;

    public function __construct(
        ?string $wsdlPath = null,
        ?callable $soapFactory = null,
        ?array $credentials = null,
        ?array $config = null
    ) {
        $this->wsdlOverride = $wsdlPath;
        $this->soapFactory  = $soapFactory;
        $this->credentials  = $credentials;
        $this->config       = $config;
    }

    /* -----------------------------------------------------------------
     |  Plumbing: config, credentials, SOAP client, invoke()
     |------------------------------------------------------------------*/

    protected function config(): array
    {
        if ($this->config === null) {
            $this->config = (array) config('services.synergy', []);
        }

        return $this->config;
    }

    /** Settings blob (reseller_id, api_key, optional legacy wsdl_path). Read once. */
    protected function settings(): array
    {
        if ($this->credentials === null) {
            $this->credentials = (array) Setting::get('synergy', [
                'reseller_id' => null,
                'api_key'     => null,
            ]);
        }

        return $this->credentials;
    }

    protected function creds(): array
    {
        $synergy = $this->settings();

        return [
            'resellerID' => $synergy['reseller_id'] ?? null,
            'apiKey'     => $synergy['api_key'] ?? null,
        ];
    }

    /**
     * Resolve the WSDL: explicit argument, then a validated legacy
     * `synergy.wsdl_path` setting, then config('services.synergy.wsdl').
     * Arbitrary URLs/paths from settings are ignored (SSRF / file-read guard).
     */
    protected function resolveWsdl(): string
    {
        if ($this->wsdlOverride) {
            return $this->wsdlOverride;
        }

        $legacy = $this->settings()['wsdl_path'] ?? null;
        if (is_string($legacy) && $legacy !== '' && $this->isTrustedWsdlLocation($legacy)) {
            return $legacy;
        }

        return (string) ($this->config()['wsdl'] ?? base_path('resources/wsdl/synergy.wsdl'));
    }

    protected function isTrustedWsdlLocation(string $location): bool
    {
        if (preg_match('#^https://([^/:]+)(:\d+)?/#i', $location, $m)) {
            $host = strtolower($m[1]);

            return $host === 'synergywholesale.com' || str_ends_with($host, '.synergywholesale.com');
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location)) {
            return false;
        }

        $real = realpath($location);
        if ($real === false) {
            return false;
        }

        foreach ([base_path('resources'), storage_path('app')] as $root) {
            $root = realpath($root);
            if ($root !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    protected function resolveEndpoint(): ?string
    {
        $cfg = $this->config();

        if (! empty($cfg['sandbox']) && ! empty($cfg['sandbox_endpoint'])) {
            return (string) $cfg['sandbox_endpoint'];
        }

        return ! empty($cfg['endpoint']) ? (string) $cfg['endpoint'] : null;
    }

    protected function soapOptions(): array
    {
        $cfg = $this->config();

        $cache = match (strtolower((string) ($cfg['wsdl_cache'] ?? 'disk'))) {
            // WSDL_CACHE_* literals: the constants only exist with ext-soap loaded.
            'none'   => 0,
            'memory' => 2,
            'both'   => 3,
            default  => 1,
        };

        $options = [
            'trace'              => false, // trace would retain request XML (incl. apiKey) in memory
            'exceptions'         => true,
            'cache_wsdl'         => $cache,
            'connection_timeout' => (int) ($cfg['connection_timeout'] ?? 10),
            'user_agent'         => 'DomainDash/1.0',
            'stream_context'     => stream_context_create([
                'http' => ['timeout' => (float) ($cfg['timeout'] ?? 30)],
                'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
            ]),
        ];

        if ($endpoint = $this->resolveEndpoint()) {
            $options['location'] = $endpoint;
        }

        return $options;
    }

    protected function soap(): object
    {
        if ($this->soap !== null) {
            return $this->soap;
        }

        $wsdl    = $this->resolveWsdl();
        $options = $this->soapOptions();

        if ($this->soapFactory) {
            return $this->soap = ($this->soapFactory)($wsdl, $options);
        }

        if (! class_exists(SoapClient::class)) {
            throw new SynergyApiException('The PHP soap extension (ext-soap) is not installed; cannot call Synergy Wholesale.');
        }

        try {
            return $this->soap = new SoapClient($wsdl, $options);
        } catch (SoapFault $e) {
            throw new SynergyApiException('Unable to load the Synergy Wholesale WSDL: ' . $e->getMessage(), null, null, 0, $e);
        }
    }

    /**
     * Execute one SOAP operation and enforce the status contract.
     *
     * @param array $params  operation parameters (credentials are added here)
     * @param array $opts    strict: bool  require status OK / OK_* (writes)
     *                       accept: string[] extra statuses treated as success
     *                       skipStatusCheck: bool  (responses with no status field)
     */
    protected function invoke(string $operation, array $params = [], array $opts = []): array
    {
        $payload = array_merge($this->creds(), $params);
        $soap    = $this->soap();

        $previousTimeout = ini_get('default_socket_timeout');
        $timeout = (int) ($this->config()['timeout'] ?? 30);
        if ($timeout > 0) {
            @ini_set('default_socket_timeout', (string) $timeout);
        }

        try {
            $response = $soap->__soapCall($operation, [$payload]);
        } catch (SoapFault $e) {
            throw new SynergyApiException(
                "Synergy {$operation} request failed: " . $e->getMessage(),
                null,
                $operation,
                0,
                $e
            );
        } finally {
            if ($previousTimeout !== false) {
                @ini_set('default_socket_timeout', (string) $previousTimeout);
            }
        }

        $result = is_array($response) ? $response : (array) $response;

        if (empty($opts['skipStatusCheck'])) {
            $this->assertSuccess($operation, $result, $opts);
        }

        return $result;
    }

    protected function assertSuccess(string $operation, array $result, array $opts): void
    {
        $status = $result['status'] ?? null;

        if (! is_string($status) || $status === '') {
            if (! empty($opts['strict'])) {
                throw new SynergyApiException("Synergy {$operation} returned no status.", null, $operation);
            }

            return;
        }

        $upper  = strtoupper(trim($status));
        $accept = array_map('strtoupper', (array) ($opts['accept'] ?? []));

        if (in_array($upper, $accept, true)) {
            return;
        }

        $failed = str_starts_with($upper, 'ERR');
        if (! $failed && ! empty($opts['strict'])) {
            $failed = ! str_starts_with($upper, 'OK');
        }

        if (! $failed) {
            return;
        }

        $message = trim((string) ($result['errorMessage'] ?? '')) ?: $status;

        if (in_array($upper, ['ERR_LOGIN_FAILED', 'ERR_RESELLER_AUTH_FAILED'], true)) {
            throw new SynergyAuthException(
                'Synergy authentication failed (' . $status . '): check the reseller ID and API key, and that this '
                . "server's outbound IP address is on the Synergy allow-list.",
                $status,
                $operation
            );
        }

        throw new SynergyApiException("Synergy {$operation} failed ({$status}): {$message}", $status, $operation);
    }

    /**
     * Normalize SOAP array-like payloads to plain PHP arrays of arrays.
     *
     * Synergy SOAP responses often return stdClass entries inside array fields,
     * and a single-element array is decoded as a bare object.
     */
    protected function normalizeSoapEntries(mixed $value): array
    {
        if (is_object($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_map(function ($entry) {
            if (is_object($entry)) {
                return (array) $entry;
            }

            return is_array($entry) ? $entry : [];
        }, array_values($value));
    }

    /** Normalise a list of scalars (e.g. nameServerResponse) to string[]. */
    protected function normalizeStringList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_object($value)) {
            $value = (array) $value;
        }

        if (is_string($value)) {
            return [$value];
        }

        return array_values(array_filter(array_map(
            static fn ($v) => is_scalar($v) ? trim((string) $v) : null,
            (array) $value
        ), static fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * Normalise a contact array to the documented unprefixed field names.
     * Accepts the documented names plus the legacy keys older callers used
     * (name/first_name/city/registrantName, ...).
     *
     * @return array{organisation:string,firstname:string,lastname:string,address:array,suburb:string,state:string,country:string,postcode:string,phone:string,fax:string,email:string}
     */
    protected function normalizeContact(array $c): array
    {
        // Strip the legacy "registrant" prefix: registrantCity => city.
        foreach ($c as $k => $v) {
            if (is_string($k) && preg_match('/^registrant([A-Z].*)$/', $k, $m)) {
                $c[lcfirst($m[1])] ??= $v;
            }
        }

        $pick = static function (array $keys) use ($c) {
            foreach ($keys as $k) {
                if (isset($c[$k]) && $c[$k] !== '') {
                    return $c[$k];
                }
            }

            return '';
        };

        $first = (string) $pick(['firstname', 'first_name', 'firstName']);
        $last  = (string) $pick(['lastname', 'last_name', 'lastName']);

        if ($first === '' && $last === '') {
            $name  = trim((string) $pick(['contactName', 'primary_contact_name']));
            if ($name === '') {
                $name = trim((string) $pick(['name']));
            }
            if ($name !== '') {
                $parts = preg_split('/\s+/', $name, 2);
                $first = $parts[0];
                $last  = $parts[1] ?? $parts[0];
            }
        }

        $organisation = (string) $pick(['organisation', 'organization', 'business_name', 'company']);
        if ($organisation === '' && isset($c['name']) && ($c['firstname'] ?? $c['first_name'] ?? '') !== '') {
            $organisation = (string) $c['name'];
        }

        $address = $pick(['address', 'address1', 'street']);
        $address = is_array($address) ? array_values(array_map('strval', $address)) : ($address === '' ? [] : [(string) $address]);

        return [
            'organisation' => $organisation,
            'firstname'    => $first,
            'lastname'     => $last,
            'address'      => $address,
            'suburb'       => (string) $pick(['suburb', 'city']),
            'state'        => (string) $pick(['state']),
            'country'      => strtoupper((string) $pick(['country'])),
            'postcode'     => (string) $pick(['postcode', 'postCode', 'zip']),
            'phone'        => (string) $pick(['phone']),
            'fax'          => (string) $pick(['fax']),
            'email'        => (string) $pick(['email']),
        ];
    }

    /** @throws InvalidArgumentException when mandatory contact fields are empty */
    protected function assertContact(array $contact, string $what): void
    {
        $missing = [];
        foreach (['firstname', 'lastname', 'address', 'suburb', 'state', 'country', 'postcode', 'phone', 'email'] as $f) {
            if ($contact[$f] === '' || $contact[$f] === []) {
                $missing[] = $f;
            }
        }

        if ($missing) {
            throw new InvalidArgumentException("Missing mandatory {$what} contact field(s): " . implode(', ', $missing));
        }
    }

    protected function boolFlag(mixed $v): bool
    {
        if (is_string($v)) {
            return in_array(strtolower(trim($v)), ['1', 'true', 'yes', 'y', 'on'], true);
        }

        return (bool) $v;
    }

    /* -----------------------------------------------------------------
     |  Nameservers
     |------------------------------------------------------------------*/

    /**
     * Update nameservers and DNS config for a domain (`updateNameServers`).
     *
     * `nameServers` is mandatory per the docs (an array); the DNS config
     * values accepted are 1-5 and 7 (v3.18 adds 5 = Default Nameservers and
     * 7 = Wholesale Hosting). For modes that do not use custom nameservers
     * (2/3/4) and no list was supplied, the documented default nameservers
     * are sent so the array is never empty.
     */
    public function updateNameServers(
        string $domainName,
        array $nameservers = [],
        ?int $dnsConfig = null,
        ?int $dnsConfigType = null
    ): array {
        if ($dnsConfig !== null && ! in_array($dnsConfig, [1, 2, 3, 4, 5, 6, 7], true)) {
            throw new InvalidArgumentException("Unsupported dnsConfig value {$dnsConfig}.");
        }

        $nameservers = array_values(array_filter($nameservers));
        if ($nameservers === []) {
            if ($dnsConfig === null || $dnsConfig === 1) {
                throw new InvalidArgumentException('Custom nameservers (dnsConfig 1) require at least one nameserver.');
            }
            $nameservers = self::DEFAULT_NAMESERVERS;
        }

        $params = [
            'domainName'  => $domainName,
            'nameServers' => $nameservers,
        ];

        if ($dnsConfig !== null) {
            $params['dnsConfig'] = $dnsConfig;
        }

        if ($dnsConfigType !== null) {
            $params['dnsConfigType'] = $dnsConfigType;
        }

        return $this->invoke('updateNameServers', $params, ['strict' => true]);
    }

    /* -----------------------------------------------------------------
     |  Domains - info / auth code
     |------------------------------------------------------------------*/

    /** Single domain info (wraps `domainInfo`). */
    public function domainInfo(string $domainName): array
    {
        return $this->invoke('domainInfo', ['domainName' => $domainName]);
    }

    /**
     * Nameservers + DNS config for the DNS page. Thin wrapper over
     * `domainInfo` returning only what callers need.
     *
     * @return array{nameServers:string[],dnsConfig:?int,dnsConfigName:?string,status:mixed}
     */
    public function getNameserversAndConfig(string $domainName): array
    {
        $info = $this->domainInfo($domainName);

        return [
            'nameServers'   => $this->normalizeStringList($info['nameServers'] ?? []),
            'dnsConfig'     => isset($info['dnsConfig']) ? (int) $info['dnsConfig'] : null,
            'dnsConfigName' => isset($info['dnsConfigName']) ? (string) $info['dnsConfigName'] : null,
            'status'        => $info['status'] ?? null,
        ];
    }

    /**
     * Info for many domains (`bulkDomainInfo`), batched in chunks of 100.
     * Returns one array per requested domain (including per-entry ERR_*
     * rows such as ERR_DOMAIN_NOT_FOUND, which callers may skip), with the
     * nameServers field normalised to string[].
     *
     * @param  string[] $domainNames
     * @return array<int,array>
     */
    public function bulkDomainInfo(array $domainNames): array
    {
        $domainNames = array_values(array_unique(array_filter(array_map('strval', $domainNames))));
        $rows = [];

        foreach (array_chunk($domainNames, self::BULK_CHUNK) as $chunk) {
            $res = $this->invoke('bulkDomainInfo', ['domainList' => $chunk]);

            foreach ($this->normalizeSoapEntries($res['domainList'] ?? []) as $entry) {
                $entry['nameServers'] = $this->normalizeStringList($entry['nameServers'] ?? []);
                $rows[] = $entry;
            }
        }

        return $rows;
    }

    /**
     * Convenience helper that just returns the EPP/auth code string,
     * or null if none is present.
     */
    public function getDomainPassword(string $domainName): ?string
    {
        $info = $this->domainInfo($domainName);

        $code = $info['domainPassword'] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }

    /* -----------------------------------------------------------------
     |  DNS - zone + records
     |------------------------------------------------------------------*/

    /** DNS Zone list. API errors throw; an empty zone returns []. */
    public function listDNSZone(string $domain): array
    {
        $res = $this->invoke('listDNSZone', ['domainName' => $domain]);

        // WSDL: listDNSZoneResponse.records (tns:listDNSZoneArray)
        $records = $res['records'] ?? [];

        // SOAP returns a single stdClass when there's only one record.
        if (is_object($records)) {
            $records = [$records];
        }

        return is_array($records) ? array_values($records) : [];
    }

    protected function assertDnsRecord(string $host, string $type, string $content, int $ttl, int $prio): string
    {
        $type = strtoupper(trim($type));

        if (! in_array($type, self::DNS_RECORD_TYPES, true)) {
            throw new InvalidArgumentException('Unsupported DNS record type "' . $type . '"; expected one of ' . implode(', ', self::DNS_RECORD_TYPES) . '.');
        }

        if (trim($host) === '') {
            throw new InvalidArgumentException('DNS record host/name is required.');
        }

        if (trim($content) === '') {
            throw new InvalidArgumentException('DNS record content is required.');
        }

        if ($ttl < 1) {
            throw new InvalidArgumentException('DNS record TTL must be a positive number of seconds.');
        }

        if ($prio < 0 || $prio > 65535) {
            throw new InvalidArgumentException('DNS record priority must be between 0 and 65535.');
        }

        return $type;
    }

    public function addDNSRecord(
        string $domainName,
        string $host,
        string $type,
        string $content,
        int $ttl = 3600,
        int $prio = 0
    ): array {
        $type = $this->assertDnsRecord($host, $type, $content, $ttl, $prio);

        return $this->invoke('addDNSRecord', [
            'domainName'    => $domainName,
            'recordName'    => $host,
            'recordType'    => $type,
            'recordContent' => $content,
            'recordTTL'     => $ttl,
            'recordPrio'    => $type === 'MX' ? $prio : 0,
        ], ['strict' => true]);
    }

    /**
     * Update a record in place using Synergy's native `updateDNSRecord`
     * (one atomic call, no delete-then-add window). The response `id` is the
     * new record ID (IDs are a hash of host/type/content, docs p.81).
     */
    public function updateDNSRecord(
        string $domainName,
        string $recordId,
        string $host,
        string $type,
        string $content,
        int $ttl = 3600,
        int $prio = 0
    ): array {
        $type = $this->assertDnsRecord($host, $type, $content, $ttl, $prio);

        return $this->invoke('updateDNSRecord', [
            'domainName'    => $domainName,
            'recordID'      => $recordId,
            'recordName'    => $host,
            'recordType'    => $type,
            'recordContent' => $content,
            'recordTTL'     => (string) $ttl,
            'recordPrio'    => $type === 'MX' ? $prio : 0,
        ], ['strict' => true]);
    }

    public function deleteDNSRecord(string $domainName, string $recordId): array
    {
        return $this->invoke('deleteDNSRecord', [
            'domainName' => $domainName,
            'recordID'   => $recordId,
        ], ['strict' => true]);
    }

    /* -----------------------------------------------------------------
     |  Hosting Services
     |------------------------------------------------------------------*/

    /**
     * Get hosting service details including password.
     * The success status here is the service state (e.g. "Active"), not "OK",
     * so only ERR_* statuses are treated as failures (docs p.110).
     *
     * @param string      $identifier Domain, username, or hoid
     * @param string|null $hoid       Optional HOID for more specific lookup
     */
    public function hostingGetService(string $identifier, ?string $hoid = null): array
    {
        $params = ['identifier' => $identifier];

        if ($hoid !== null) {
            $params['hoid'] = $hoid;
        }

        return $this->invoke('hostingGetService', $params);
    }

    /** Get cPanel SSO login URL. */
    public function hostingGetLogin(string $identifier, ?string $hoid = null): array
    {
        $params = ['identifier' => $identifier];

        if ($hoid !== null) {
            $params['hoid'] = $hoid;
        }

        return $this->invoke('hostingGetLogin', $params);
    }

    /**
     * List hosting services with pagination.
     * The docs show the result key as both `domainList` and `hoidList`
     * (p.108-109); both are normalised and exposed as `hoidList`.
     */
    public function listHosting(?string $status = null, int $page = 1, int $limit = 100): array
    {
        $params = ['page' => $page, 'limit' => $limit];

        if ($status !== null) {
            $params['status'] = $status;
        }

        $payload = $this->invoke('listHosting', $params);

        $list = $payload['hoidList'] ?? $payload['domainList'] ?? null;
        if ($list !== null) {
            $payload['hoidList'] = $this->normalizeSoapEntries($list);
        }

        return $payload;
    }

    /** List available hosting packages from Synergy. */
    public function hostingListPackages(): array
    {
        return $this->invoke('hostingListPackages');
    }

    /* -----------------------------------------------------------------
     |  Domain Registration & Transfer
     |------------------------------------------------------------------*/

    /**
     * Check domain availability (`checkDomain`).
     *
     * @return array payload with a normalised boolean `available`
     */
    public function checkDomain(string $domainName, string $command = 'create', ?int $years = null): array
    {
        $params = [
            'domainName' => $domainName,
            // Synergy docs: command defaults to "create" for availability/pricing checks.
            'command'    => $command,
        ];

        if ($years !== null) {
            $params['years'] = $years;
        }

        // AVAILABLE / UNAVAILABLE are normal outcomes, only ERR_* throws.
        $payload = $this->invoke('checkDomain', $params);

        $availableRaw = $payload['available'] ?? null;
        $available = null;

        if (is_bool($availableRaw)) {
            $available = $availableRaw;
        } elseif (is_numeric($availableRaw)) {
            $available = ((int) $availableRaw) === 1;
        } elseif (is_string($availableRaw)) {
            $normalized = strtolower(trim($availableRaw));
            if (in_array($normalized, ['1', 'true', 'yes'], true)) {
                $available = true;
            } elseif (in_array($normalized, ['0', 'false', 'no'], true)) {
                $available = false;
            }
        }

        if ($available === null) {
            $status = strtolower(trim((string) ($payload['status'] ?? '')));
            $available = in_array($status, ['available', 'ok', 'success'], true)
                && empty($payload['errorMessage']);
        }

        $payload['available'] = $available;

        return $payload;
    }

    /**
     * Generate .au eligibility fields from a business identifier.
     *
     * @param string      $registrationIdentifier ABN/ACN/RBN value
     * @param string|null $type                   Optional type hint (ABN, ACN, RBN)
     */
    public function generateAuEligibility(string $registrationIdentifier, ?string $type = null): array
    {
        $params = ['registrationIdentifier' => $registrationIdentifier];

        if (! empty($type)) {
            $params['type'] = $type;
        }

        return $this->invoke('generateAuEligibility', $params);
    }

    /** Backwards-compatible alias for older callers. */
    public function auRegistrantInfo(string $idType, string $idValue): array
    {
        return $this->generateAuEligibility($idValue, $idType);
    }

    /**
     * Fold the legacy flat .au keys (eligibilityType/Name/ID/IDType) into the
     * single JSON `eligibility` string domainRegister expects.
     */
    protected function buildEligibilityJson(array $extra): ?string
    {
        if (isset($extra['eligibility'])) {
            return is_string($extra['eligibility']) ? $extra['eligibility'] : json_encode($extra['eligibility']);
        }

        if (! isset($extra['eligibilityID'], $extra['eligibilityIDType'])) {
            return null;
        }

        $idType = strtoupper((string) $extra['eligibilityIDType']);
        $name   = (string) ($extra['eligibilityName'] ?? '');
        $fields = ['eligibilityType' => (string) ($extra['eligibilityType'] ?? 'Company')];

        if (in_array($idType, ['ABN', 'ACN'], true)) {
            $fields += [
                'registrantName'   => $name,
                'registrantID'     => (string) $extra['eligibilityID'],
                'registrantIDType' => $idType,
            ];
        } else {
            $fields += [
                'registrantIDType'  => 'OTHER',
                'eligibilityIDType' => (string) $extra['eligibilityIDType'],
                'eligibilityID'     => (string) $extra['eligibilityID'],
                'eligibilityName'   => $name,
            ];
        }

        return json_encode($fields);
    }

    /**
     * Register a new domain (SOAP operation `domainRegister`).
     *
     * @param array $contacts    registrant contact; see normalizeContact() for accepted keys
     * @param array $nameservers custom nameservers; empty = parked/default (use $extra['dnsConfig'] = 5 for Synergy defaults)
     * @param array $extra       idProtect, autoRenew, premium, costPrice, transferLock, categories,
     *                           dnsConfig, eligibility (JSON string) or legacy eligibilityType/Name/ID/IDType
     */
    public function registerDomain(
        string $domainName,
        int $years,
        array $contacts,
        array $nameservers = [],
        array $extra = []
    ): array {
        if ($years < 1 || $years > 10) {
            throw new InvalidArgumentException('Registration period must be between 1 and 10 years.');
        }

        $registrant = $this->normalizeContact($contacts);
        $this->assertContact($registrant, 'registrant');

        $params = [
            'domainName' => $domainName,
            'years'      => (string) $years,
        ];

        foreach ($registrant as $field => $value) {
            $params['registrant_' . $field] = $value;
        }

        // Billing/admin/technical default to the registrant server-side (docs p.31)
        // unless explicit contact sets are supplied under those keys.
        foreach (['technical', 'admin', 'billing'] as $role) {
            if (isset($extra[$role]) && is_array($extra[$role])) {
                $contact = $this->normalizeContact($extra[$role]);
                $this->assertContact($contact, $role);
                foreach ($contact as $field => $value) {
                    $params[$role . '_' . $field] = $value;
                }
            }
        }

        $nameservers = array_values(array_filter($nameservers));
        if ($nameservers !== []) {
            $params['nameServers'] = $nameservers;
        }

        if ($eligibility = $this->buildEligibilityJson($extra)) {
            $params['eligibility'] = $eligibility;
        }

        foreach (['autoRenew', 'premium', 'transferLock', 'skipEmail'] as $flag) {
            if (isset($extra[$flag])) {
                $params[$flag] = $this->boolFlag($extra[$flag]);
            }
        }

        if (isset($extra['idProtect'])) {
            $params['idProtect'] = $this->boolFlag($extra['idProtect']) ? 'true' : 'false';
        }

        if (isset($extra['costPrice'])) {
            $params['costPrice'] = (float) $extra['costPrice'];
        }

        if (isset($extra['dnsConfig'])) {
            $params['dnsConfig'] = (int) $extra['dnsConfig'];
        }

        if (! empty($extra['categories'])) {
            $params['categories'] = array_values((array) $extra['categories']);
        }

        return $this->invoke('domainRegister', $params, ['strict' => true]);
    }

    /**
     * Transfer a domain in (`transferDomain`).
     *
     * @param array $payload domainName, authInfo (mandatory) plus the gaining contact
     *                       (organisation, firstname, lastname, address[], suburb, state,
     *                       country, postcode, phone, email) and optional idProtect,
     *                       doRenewal, autoRenew, renewYears, premium, costPrice,
     *                       eligibility, transferLock, nameServers, categories, dnsConfig.
     *                       `years`/`authCode` are accepted as aliases for renewYears/authInfo.
     */
    public function transferDomain(array $payload): array
    {
        $domainName = trim((string) ($payload['domainName'] ?? ''));
        $authInfo   = (string) ($payload['authInfo'] ?? $payload['authCode'] ?? '');

        if ($domainName === '' || $authInfo === '') {
            throw new InvalidArgumentException('transferDomain requires domainName and authInfo.');
        }

        $contact = $this->normalizeContact($payload);
        $this->assertContact($contact, 'transfer');

        $params = ['domainName' => $domainName, 'authInfo' => $authInfo] + $contact;

        if (array_key_exists('idProtect', $payload)) {
            if ($this->boolFlag($payload['idProtect'])) {
                $params['idProtect'] = 'Y'; // docs: provide Y to enable
            }
        }

        if (array_key_exists('doRenewal', $payload)) {
            $params['doRenewal'] = $this->boolFlag($payload['doRenewal']) ? '1' : '0';
        }

        foreach (['autoRenew', 'premium', 'transferLock'] as $flag) {
            if (isset($payload[$flag])) {
                $params[$flag] = $this->boolFlag($payload[$flag]);
            }
        }

        $renewYears = $payload['renewYears'] ?? $payload['years'] ?? null;
        if ($renewYears !== null) {
            $params['renewYears'] = (int) $renewYears;
        }

        if (isset($payload['costPrice'])) {
            $params['costPrice'] = (float) $payload['costPrice'];
        }

        if (isset($payload['eligibility'])) {
            $params['eligibility'] = is_string($payload['eligibility']) ? $payload['eligibility'] : json_encode($payload['eligibility']);
        }

        if (isset($payload['dnsConfig'])) {
            $params['dnsConfig'] = (int) $payload['dnsConfig'];
        }

        foreach (['nameServers', 'categories'] as $listField) {
            if (! empty($payload[$listField])) {
                $params[$listField] = array_values(array_filter((array) $payload[$listField]));
            }
        }

        // OK and OK_TRANSFER_* are accepted by the strict check.
        return $this->invoke('transferDomain', $params, ['strict' => true]);
    }

    /**
     * Pre-flight check for an inbound transfer (`isDomainTransferrable`).
     * Throws SynergyApiException (ERR_ISDOMAINTRANSFERRABLE_FAILED) with the
     * registry's reason when the domain cannot be transferred.
     */
    public function isDomainTransferrable(string $domainName, string $authInfo): array
    {
        return $this->invoke('isDomainTransferrable', [
            'domainName' => $domainName,
            'authInfo'   => $authInfo,
        ], ['strict' => true]);
    }

    /** Renew a domain. */
    public function renewDomain(string $domainName, int $years = 1, array $extra = []): array
    {
        if ($years < 1 || $years > 10) {
            throw new InvalidArgumentException('Renewal period must be between 1 and 10 years.');
        }

        $params = ['domainName' => $domainName, 'years' => $years];

        if (isset($extra['premium'])) {
            $params['premium'] = $this->boolFlag($extra['premium']);
        }

        if (isset($extra['costPrice'])) {
            $params['costPrice'] = (float) $extra['costPrice'];
        }

        return $this->invoke('renewDomain', $params, ['strict' => true]);
    }

    /**
     * List domains (one page, max 500). `domainList` is always returned as a
     * list of stdClass entries (a single result is wrapped), so callers can
     * foreach/count it safely. Per-entry ERR_* rows are left for callers to skip.
     */
    public function listDomains(int $page = 1, int $limit = 100, ?string $status = null): array
    {
        $params = ['page' => $page, 'limit' => min(max($limit, 1), 500)];

        if ($status !== null) {
            $params['status'] = $status;
        }

        $payload = $this->invoke('listDomains', $params);

        $list = $payload['domainList'] ?? [];
        if (is_object($list)) {
            $list = [$list];
        }
        $payload['domainList'] = is_array($list) ? array_values($list) : [];

        return $payload;
    }

    /* -----------------------------------------------------------------
     |  Hosting Purchase
     |------------------------------------------------------------------*/

    /**
     * Purchase hosting (SOAP operation `hostingPurchaseService`).
     * The response carries hoid, username and the generated password: deliver
     * it, never log it.
     *
     * @param string $planName Package name from hostingListPackages
     * @param array  $extra    username, password, first_name, last_name
     */
    public function purchaseHosting(
        string $planName,
        string $domain,
        string $email,
        array $extra = []
    ): array {
        if (trim($planName) === '' || trim($domain) === '' || trim($email) === '') {
            throw new InvalidArgumentException('Hosting purchase requires planName, domain and email.');
        }

        $params = [
            'planName' => $planName,
            'domain'   => $domain,
            'email'    => $email,
        ];

        foreach (['username', 'password', 'first_name', 'last_name'] as $field) {
            if (isset($extra[$field]) && $extra[$field] !== '') {
                $params[$field] = (string) $extra[$field];
            }
        }

        return $this->invoke('hostingPurchaseService', $params, ['strict' => true]);
    }

    /* -----------------------------------------------------------------
     |  SSL Certificates
     |------------------------------------------------------------------*/

    /** List available SSL products. */
    public function listSSLProducts(): array
    {
        $payload = $this->invoke('getSSLPricing');

        $payload['pricing'] = $this->normalizeSoapEntries($payload['pricing'] ?? []);

        return $payload;
    }

    /**
     * Purchase SSL certificate.
     *
     * @param array $extra csr, privateKey and the contact fields
     */
    public function purchaseSSL(
        string $productId,
        string $domain,
        int $years = 1,
        array $extra = []
    ): array {
        $defaults = [
            'privateKey' => '',
            'csr' => '',
            'firstName' => '',
            'lastName' => '',
            'emailAddress' => '',
            'address' => '',
            'city' => '',
            'state' => '',
            'postCode' => '',
            'country' => '',
            'phone' => '',
            'fax' => '',
            'businessCategory' => null,
        ];

        $params = array_merge($defaults, ['productID' => $productId], $extra);

        $missing = [];
        foreach (['firstName', 'lastName', 'emailAddress', 'address', 'city', 'state', 'postCode', 'country', 'phone'] as $f) {
            if (trim((string) ($params[$f] ?? '')) === '') {
                $missing[] = $f;
            }
        }
        if ($missing) {
            throw new InvalidArgumentException('Missing mandatory SSL contact field(s): ' . implode(', ', $missing));
        }

        $this->sslCertCache = null;

        return $this->invoke('SSL_purchaseSSLCertificate', $params, ['strict' => true]);
    }

    /**
     * List all SSL certificates in the reseller account. The response embeds
     * every certificate body, so it is memoised on this instance for 60s.
     */
    public function listAllSSLCerts(bool $refresh = false): array
    {
        if (! $refresh && $this->sslCertCache !== null && (time() - $this->sslCertCache['at']) < 60) {
            return $this->sslCertCache['payload'];
        }

        $payload = $this->invoke('SSL_listAllCerts');
        $payload['certs'] = $this->normalizeSoapEntries($payload['certs'] ?? []);

        $this->sslCertCache = ['at' => time(), 'payload' => $payload];

        return $payload;
    }

    public function getSSLCertificate(string $certId): array
    {
        return $this->invoke('SSL_getSSLCertificate', ['certID' => $certId]);
    }

    public function getSSLCertSimpleStatus(string $certId): array
    {
        return $this->invoke('SSL_getCertSimpleStatus', ['certID' => $certId]);
    }

    public function renewSSLCertificate(string $certId, array $contact): array
    {
        $defaults = [
            'firstName' => '',
            'lastName' => '',
            'emailAddress' => '',
            'address' => '',
            'city' => '',
            'state' => '',
            'postCode' => '',
            'country' => '',
            'phone' => '',
            'fax' => '',
        ];

        $this->sslCertCache = null;

        return $this->invoke(
            'SSL_renewSSLCertificate',
            array_merge($defaults, ['certID' => $certId], $contact),
            ['strict' => true]
        );
    }

    public function reissueSSLCertificate(string $certId, string $newCsr): array
    {
        $this->sslCertCache = null;

        return $this->invoke('SSL_reissueCertificate', [
            'certID' => $certId,
            'newCSR' => $newCsr,
        ], ['strict' => true]);
    }

    public function resendIssuedCertificateEmail(string $certId): array
    {
        return $this->invoke('SSL_resendIssuedCertificateEmail', ['certID' => $certId], ['strict' => true]);
    }

    public function decodeSSLCsr(string $csr): array
    {
        return $this->invoke('SSL_decodeCSR', ['csr' => $csr]);
    }

    public function getCsrForCertificate(string $certId): ?string
    {
        $payload = $this->listAllSSLCerts();

        foreach ($payload['certs'] ?? [] as $entry) {
            if ((string) ($entry['certID'] ?? '') === $certId) {
                $csr = trim((string) ($entry['csr'] ?? ''));
                return $csr !== '' ? $csr : null;
            }
        }

        return null;
    }

    /* -----------------------------------------------------------------
     |  Account Balance
     |------------------------------------------------------------------*/

    /** Get account balance (also a cheap "test connection" call). */
    public function balanceQuery(): array
    {
        return $this->invoke('balanceQuery');
    }
}
