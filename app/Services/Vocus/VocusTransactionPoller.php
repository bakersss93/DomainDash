<?php

namespace App\Services\Vocus;

use App\Models\InternetService;
use Illuminate\Support\Facades\Log;

/**
 * Polls a service's last Vocus transaction (TCAS Get RESPONSE) and applies the result to the service.
 * Used by "Check status" on the service page and by the scheduled poll (PollVocusTransactionsJob).
 */
class VocusTransactionPoller
{
    public function __construct(private readonly VocusWsmClient $vocus)
    {
    }

    /**
     * @return array{state:?string,final:bool,fault_code:?string,fault_message:?string,params:array}
     *
     * @throws VocusWsmException after marking an unknown (TCAS-4300) or expired (TCAS-4310) transaction as final
     */
    public function poll(InternetService $service): array
    {
        try {
            $result = VocusWsmClient::normalizeTransactionResult($this->vocus->pollTransaction((string) $service->last_transaction_id));
        } catch (VocusWsmException $e) {
            // Unknown / expired transaction: nothing will ever change, stop the pending state.
            if (self::isExpired($e)) {
                $service->update([
                    'last_transaction_state' => $e->errorCode === 'TCAS-4310' ? 'EXPIRED' : 'UNKNOWN',
                    'last_fault_code'        => $e->errorCode,
                    'last_fault_message'     => mb_substr($e->getMessage(), 0, 500),
                    'last_polled_at'         => now(),
                ]);
            }
            throw $e;
        }

        $state = $result['state'];
        $service->last_polled_at = now();

        if ($state) {
            $service->last_transaction_state = $state;

            if ($state === 'FAILED') {
                // Persist the TCAS faultcode / faultmessage so the details survive the page reload.
                $service->last_fault_code    = $result['fault_code'] ? mb_substr($result['fault_code'], 0, 40) : null;
                $service->last_fault_message = $result['fault_message'] ? mb_substr($result['fault_message'], 0, 500) : null;
            } else {
                $service->last_fault_code = $service->last_fault_message = null;
            }

            // A SUCCESS on Create carries the provisioning results (AVCID, CVCID, NTDID, DataPortID, NBNInstanceID,
            // IPAssignment, IPAddress ...: FIBRE 4.1.1.2 response params); keep what is in the known columns.
            if ($state === 'SUCCESS') {
                $service->applyLiveParams(array_diff_key($result['params'], ['ServiceStatus' => 1, 'TransactionState' => 1]));
            }

            // SUCCESS may be the result of a suspend or cancel, so the new
            // service status comes from the live record, never assumed ACTIVE.
            if ($state === 'SUCCESS' && $service->vocus_service_id) {
                try {
                    $live = $this->vocus->getService($service->vocus_service_id)['params'] ?? [];
                    if ($new = VocusWsmClient::resolveServiceStatus($state, $live['ServiceStatus'] ?? null)) {
                        $service->service_status = $new;
                    }
                    if ($live) {
                        $service->applyLiveParams($live);
                    }
                } catch (\Throwable $e) {
                    Log::warning('Vocus status refresh after transaction failed', [
                        'service_id' => $service->vocus_service_id,
                        'message'    => $e->getMessage(),
                    ]);
                }
            }
        }
        $service->save();

        return $result;
    }

    public static function isExpired(VocusWsmException $e): bool
    {
        return in_array($e->errorCode, ['TCAS-4300', 'TCAS-4310'], true);
    }
}
