<?php

namespace App\Services\Halo;

use App\Models\Setting;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class HaloPsaClient
{
    protected Client $http;

    /** @var string Base URL for the Halo resource server (e.g. https://yourtenant.halopsa.com/api) */
    protected string $resourceBase;

    /** @var string Base URL for the Halo auth server (e.g. https://auth.halopsa.com/auth) */
    protected string $authBase;

    protected ?string $tenant;
    protected ?string $clientId;
    protected ?string $clientSecret;

    /** @var string|null */
    protected ?string $accessToken = null;

    /** @var int|null Unix timestamp when token expires */
    protected ?int $tokenExpiresAt = null;

    public function __construct()
    {
        $config = Setting::get('halo', []);

        // Base/resource URL
        $resource = rtrim($config['resource_server'] ?? $config['base_url'] ?? '', '/');
        if ($resource === '') {
            throw new \RuntimeException('HaloPSA resource server URL is not configured (settings.halo.resource_server / base_url).');
        }

        // Most docs show the resource server already including /api
        if (!str_ends_with($resource, '/api')) {
            $resource .= '/api';
        }
        $this->resourceBase = $resource;

        // Auth URL
        // If auth_server is explicitly set, use that; otherwise derive from base_url.
        $auth = rtrim($config['auth_server'] ?? '', '/');
        if ($auth === '') {
            // Fallback: same host, /auth
            // e.g. https://support.haloservicedesk.com/auth
            $parsed = parse_url($resource);
            $scheme = $parsed['scheme'] ?? 'https';
            $host   = $parsed['host'] ?? '';
            if (!$host) {
                throw new \RuntimeException('Cannot derive HaloPSA auth URL from resource server.');
            }
            $auth = $scheme . '://' . $host . '/auth';
        }
        $this->authBase = $auth;

        $this->tenant       = $config['tenant']    ?? null;
        $this->clientId     = $config['client_id'] ?? null;
        // We keep your existing naming – API Key field in settings is actually the Client Secret
        $this->clientSecret = $config['api_key']   ?? null;

        $this->http = new Client([
            'timeout' => 20,
            'verify'  => $config['verify_ssl'] ?? true,
        ]);
    }

    /**
     * Get a valid access token, refreshing if necessary.
     */
    protected function getAccessToken(): string
    {
        // Re-use token if it’s still valid for at least 60 seconds
        if ($this->accessToken && $this->tokenExpiresAt && $this->tokenExpiresAt > (time() + 60)) {
            return $this->accessToken;
        }

        if (!$this->clientId || !$this->clientSecret) {
            throw new \RuntimeException('HaloPSA client ID or API key is not configured in settings.');
        }

        $tokenUrl = rtrim($this->authBase, '/') . '/token';

        $formParams = [
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope'         => 'all',
        ];

        // Hosted Halo typically expects tenant as query string (?tenant=xxx)
        $query = [];
        if ($this->tenant) {
            $query['tenant'] = $this->tenant;
        }

        try {
            $response = $this->http->post($tokenUrl, [
                'query'       => $query,
                'form_params' => $formParams,
                'headers'     => ['Accept' => 'application/json'],
            ]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Failed to request HaloPSA access token: ' . $e->getMessage(), 0, $e);
        }

        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data) || empty($data['access_token'])) {
            throw new \RuntimeException('HaloPSA token response did not contain access_token.');
        }

        $this->accessToken = $data['access_token'];
        $expiresIn         = isset($data['expires_in']) ? (int) $data['expires_in'] : 3600;

        // Store expiry ~1 minute early as a safety buffer
        $this->tokenExpiresAt = time() + max($expiresIn - 60, 60);

        return $this->accessToken;
    }

    protected function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ];
    }

    protected function get(string $path, array $query = []): array
    {
        try {
            $response = $this->http->get($this->resourceBase . $path, [
                'headers' => $this->headers(),
                'query'   => $query,
            ]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException("HaloPSA GET {$path} failed: " . $e->getMessage(), 0, $e);
        }

        return json_decode((string) $response->getBody(), true) ?? [];
    }

    protected function post(string $path, array $body): array
    {
        try {
            $response = $this->http->post($this->resourceBase . $path, [
                'headers' => $this->headers(),
                'json'    => $body,
            ]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException("HaloPSA POST {$path} failed: " . $e->getMessage(), 0, $e);
        }

        return json_decode((string) $response->getBody(), true) ?? [];
    }

    // ---------------------------------------------------------------------
    // High-level helpers – you can hook these into your sync logic later
    // ---------------------------------------------------------------------

    /**
     * List customers/clients from Halo.
     * Typical endpoint: GET /Customers
     */
    public function listCustomers(array $query = []): array
    {
        return $this->get('/Customers', $query);
    }

    /**
     * List assets – you can filter on asset type, tags, etc.
     * Typical endpoint: GET /Assets
     */
    public function listAssets(array $query = []): array
    {
        return $this->get('/Assets', $query);
    }

    /**
     * List invoices for a customer.
     * Typical endpoint: GET /Invoices
     */
    public function listInvoices(array $query = []): array
    {
        return $this->get('/Invoices', $query);
    }

    /**
     * List tickets – used later for "view open tickets" in the portal.
     * Typical endpoint: GET /Tickets
     */
    public function listTickets(array $query = []): array
    {
        return $this->get('/Tickets', $query);
    }

    // ---------------------------------------------------------------------
    // Existing methods you already use
    // ---------------------------------------------------------------------

    /**
     * Create / update a domain asset in Halo.
     * Map $data to Halo's asset schema for your "Domain" asset type.
     */
    public function createDomainAsset(array $data): array
    {
        return $this->post('/Assets', $data);
    }

    /**
     * Create a ticket in Halo (already used by TicketController).
     */
    public function createTicket(array $data): array
    {
        return $this->post('/Tickets', $data);
    }
}
