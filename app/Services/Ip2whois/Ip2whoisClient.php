<?php

namespace App\Services\Ip2whois;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Ip2whoisClient
{
    protected string $baseUrl = 'https://api.ip2whois.com/v2';
    protected ?string $apiKey;

    public function __construct()
    {
        $cfg = Setting::get('ip2whois', []);
        $this->apiKey = $cfg['api_key'] ?? null;
    }

    /**
     * Lookup WHOIS details for a domain
     */
    public function lookup(string $domain): array
    {
        if (!$this->apiKey) {
            return ['success' => false, 'error' => 'IP2WHOIS API key is not configured'];
        }

        try {
            $resp = Http::get($this->baseUrl, [
                'key' => $this->apiKey,
                'domain' => $domain,
                'format' => 'json',
            ]);

            if (!$resp->successful()) {
                $body = $resp->json();
                $error = $body['error_message'] ?? $resp->body();
                Log::warning('IP2WHOIS lookup failed', [
                    'domain' => $domain,
                    'status' => $resp->status(),
                    'error' => $error,
                ]);
                return ['success' => false, 'error' => $error];
            }

            $data = $resp->json();

            return [
                'success' => true,
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('IP2WHOIS lookup error: ' . $e->getMessage(), [
                'domain' => $domain,
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
