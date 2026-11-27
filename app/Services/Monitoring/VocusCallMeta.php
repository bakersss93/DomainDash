<?php

namespace App\Services\Monitoring;

use App\Models\IntegrationRequest;
use App\Services\Vocus\VocusWsmClient;

/**
 * Structured, secret-free facts about one Vocus WSM call (the indexed integration_requests columns):
 * environment, product / plan / scope, TransactionID, ResponseType, result and the key identifiers.
 * Only identifiers and codes are ever read here; the AccessKey / AliasKey never pass through.
 */
final class VocusCallMeta
{
    private const MAX = 40;

    /**
     * @param array<string,mixed>      $params         request parameters
     * @param array<string,mixed>|null $responseParams decoded response parameters (null on a fault)
     * @return array<string,mixed> integration_requests attributes
     */
    public static function build(
        string $environment,
        string $productId,
        ?string $planId,
        ?string $scope,
        array $params,
        ?array $responseParams,
        ?string $transactionId,
        ?string $responseType,
        ?string $faultCode,
        bool $faulted,
    ): array {
        $responseParams ??= [];
        $product = strtoupper($productId);

        $meta = array_filter([
            'order_type' => self::scalar($params['OrderType'] ?? null),
            'transaction_state' => self::scalar($responseParams['TransactionState'] ?? null),
            'fault_code' => self::scalar($responseParams['faultcode'] ?? null),
            'service_status' => self::scalar($params['ServiceStatus'] ?? null),
        ], fn ($v) => $v !== null);

        if ($product === 'DIR' && ! $faulted && $responseParams !== []) {
            try {
                $ids = array_values(array_unique(array_column(VocusWsmClient::parseAddressRecords($responseParams)[0], 'directory_id')));
                if ($ids !== []) {
                    $meta['directory_ids'] = array_slice($ids, 0, 100);
                }
            } catch (\Throwable) {
            }
        }

        return [
            'environment' => $environment === 'sandbox' ? 'sandbox' : 'production',
            'product_id' => self::scalar($productId, 24),
            'plan_id' => self::scalar($planId),
            'scope' => self::scalar($scope),
            'transaction_id' => self::scalar($transactionId),
            'related_transaction_id' => $product === 'TCAS' ? self::scalar($params['TransactionID'] ?? null) : null,
            'response_type' => self::scalar($responseType, 12),
            'result' => $faulted ? (self::scalar($faultCode) ?? 'FAULT') : 'OK',
            'directory_id' => self::scalar($params['DirectoryID'] ?? $responseParams['DirectoryID'] ?? null),
            'service_id' => self::scalar($params['ServiceID'] ?? $responseParams['ServiceID'] ?? null),
            'avc_id' => self::scalar($params['AVCID'] ?? $responseParams['AVCID'] ?? null),
            'meta' => $meta === [] ? null : $meta,
        ];
    }

    /**
     * Re-derive the columns of a Vocus row stored before the structured columns existed, from its redacted
     * request XML and response JSON. Returns [] when nothing useful can be read.
     *
     * @return array<string,mixed>
     */
    public static function fromStoredRow(IntegrationRequest $row): array
    {
        $xml = (string) $row->request_body;
        $tag = fn (string $name) => preg_match('#<(?:\w+:)?' . $name . '>\s*([^<]*?)\s*</(?:\w+:)?' . $name . '>#i', $xml, $m) ? $m[1] : null;

        $params = [];
        if (preg_match_all('#<(?:\w+:)?Param\s+id="([^"]+)"[^>]*>\s*([^<]*?)\s*</(?:\w+:)?Param>#i', $xml, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                $v = html_entity_decode($m[2], ENT_QUOTES | ENT_XML1);
                if ($v !== '' && ! str_contains($v, '[REDACTED]') && ! isset($params[$m[1]])) {
                    $params[$m[1]] = $v;
                }
            }
        }

        $decoded = json_decode((string) $row->response_body, true);
        $respParams = [];
        $transactionId = null;
        $responseType = null;
        if (is_array($decoded)) {
            $transactionId = self::scalar($decoded['TransactionID'] ?? null);
            $responseType = self::scalar($decoded['ResponseType'] ?? null, 12);
            $list = $decoded['Parameters']['Param'] ?? [];
            if (isset($list['id'])) {
                $list = [$list];
            }
            foreach ((array) $list as $p) {
                if (is_array($p) && isset($p['id'], $p['_']) && is_scalar($p['_']) && ! isset($respParams[$p['id']])) {
                    $respParams[(string) $p['id']] = (string) $p['_'];
                }
            }
        }

        $faultCode = null;
        if ($row->is_error) {
            $text = (string) $row->error_message . ' ' . (string) $row->response_body;
            if (preg_match('/\b(WSM-\d{4})\b/', $text, $m)) {
                $faultCode = $m[1];
            }
            if ($transactionId === null && preg_match('/TransactionID[\s:\]]*([A-Za-z0-9-]{4,})/', $text, $m)) {
                $transactionId = $m[1];
            }
        }

        $product = $tag('ProductID');
        if ($product === null) {
            return [];
        }

        $host = (string) parse_url((string) $row->url, PHP_URL_HOST);
        $sandboxHost = (string) parse_url((string) config('services.vocus.sandbox.endpoint'), PHP_URL_HOST);
        $prodHost = (string) parse_url((string) config('services.vocus.production.endpoint'), PHP_URL_HOST);
        // Sandbox and production can share a host name (the sandbox is reached by pinned IP), so an unclear
        // row is only labelled when the two hosts differ.
        $env = $host !== '' && $sandboxHost !== $prodHost ? ($host === $sandboxHost ? 'sandbox' : 'production') : null;

        $cols = self::build($env ?? 'production', $product, $tag('PlanID'), $tag('Scope'), $params, $respParams ?: null, $transactionId, $responseType, $faultCode, (bool) $row->is_error);
        if ($env === null) {
            $cols['environment'] = null;
        }

        return $cols;
    }

    private static function scalar(mixed $value, int $max = self::MAX): ?string
    {
        if (! is_scalar($value) || is_bool($value)) {
            return null;
        }
        $v = trim((string) $value);

        return $v === '' ? null : mb_substr($v, 0, $max);
    }
}
