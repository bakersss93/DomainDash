<?php

namespace App\Services\ItGlue;

use App\Models\Setting;
use GuzzleHttp\Client;

class ItGlueClient
{
    protected Client $http;

    protected string $baseUrl;
    protected ?string $apiKey;

    public function __construct()
    {
        $cfg = Setting::get('itglue', []);

        // Base URL – default to official ITGlue API if not configured
        $base = trim($cfg['base_url'] ?? '');
        if ($base === '') {
            $base = 'https://api.itglue.com';
        }

        $this->baseUrl = rtrim($base, '/');
        $this->apiKey  = $cfg['api_key'] ?? null;

        $this->http = new Client([
            'base_uri' => $this->baseUrl,
            'timeout'  => 15,
        ]);
    }

    protected function headers(): array
    {
        if (!$this->apiKey) {
            throw new \RuntimeException('ITGlue API key is not configured in settings.');
        }

        return [
            'x-api-key'    => $this->apiKey,
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * List organisations from ITGlue.
     * Returns the decoded JSON; your controller can normalise it.
     */
    public function listOrganisations(): array
    {
        $resp = $this->http->get('/organizations', [
            'headers' => $this->headers(),
        ]);

        $data = json_decode((string) $resp->getBody(), true);

        return is_array($data) ? $data : [];
    }

    public function createDomainConfiguration(array $payload): array
    {
        $resp = $this->http->post('/configurations', ['headers' => $this->headers(), 'json' => $payload]);
        return json_decode((string)$resp->getBody(), true);
    }
}