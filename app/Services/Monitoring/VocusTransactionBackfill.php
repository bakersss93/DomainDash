<?php

namespace App\Services\Monitoring;

use App\Models\IntegrationRequest;

/**
 * Fills the structured Vocus columns of integration_requests rows recorded before they existed.
 * Idempotent: only rows without a product_id are touched.
 */
class VocusTransactionBackfill
{
    public function run(): int
    {
        $updated = 0;

        IntegrationRequest::query()
            ->where('provider', 'vocus')->where('direction', IntegrationRequest::OUTBOUND)->whereNull('product_id')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$updated) {
                foreach ($rows as $row) {
                    $cols = VocusCallMeta::fromStoredRow($row);
                    if ($cols === []) {
                        continue;
                    }
                    IntegrationRequest::query()->whereKey($row->id)->update($this->encode($cols));
                    $updated++;
                }
            });

        return $updated;
    }

    /** Query-builder updates bypass casts: encode the JSON column by hand. */
    private function encode(array $cols): array
    {
        if (array_key_exists('meta', $cols)) {
            $cols['meta'] = $cols['meta'] === null ? null : json_encode($cols['meta']);
        }

        return $cols;
    }
}
