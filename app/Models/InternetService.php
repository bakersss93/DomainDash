<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InternetService extends Model
{
    protected $fillable = [
        'vocus_service_id', 'client_id', 'plan_id', 'vocus_plan_id', 'cost_price', 'sell_price', 'service_scope', 'service_status',
        'service_type', 'order_type', 'customer_name', 'phone', 'directory_id',
        'location_reference', 'address_long', 'nbn_instance_id', 'avc_id', 'cvc_id',
        'copper_pair_id', 'ntd_id', 'uni_port', 'service_class', 'realm', 'username', 'service_level', 'billing_provider_id',
        'auth_type', 'ip_assignment', 'ip_address', 'reference', 'central_splitter',
        'last_transaction_id', 'last_transaction_state', 'last_fault_code', 'last_fault_message',
        'last_polled_at', 'notes', 'synced_at',
    ];

    protected $casts = [
        'synced_at' => 'datetime',
        'last_polled_at' => 'datetime',
        'cost_price' => 'decimal:2',
        'sell_price' => 'decimal:2',
    ];

    /** Column widths, so a long value from Vocus can never abort a save ("Data too long"). */
    public const LIMITS = [
        'plan_id' => 30, 'service_scope' => 30, 'service_status' => 10, 'service_type' => 20,
        'order_type' => 20, 'customer_name' => 100, 'phone' => 20, 'directory_id' => 30,
        'location_reference' => 10, 'address_long' => 255, 'nbn_instance_id' => 30, 'avc_id' => 30,
        'cvc_id' => 30, 'copper_pair_id' => 30, 'ntd_id' => 30, 'uni_port' => 30, 'service_class' => 10, 'realm' => 64, 'username' => 100,
        'service_level' => 20, 'billing_provider_id' => 30,
        'auth_type' => 10, 'ip_assignment' => 10, 'ip_address' => 46, 'reference' => 30, 'central_splitter' => 5,
    ];

    /** Get SERVICE response parameter => column (Product Catalogue FIBRE 4.2.2; also the TCAS response of Create). */
    public const LIVE_FIELD_MAP = [
        'ServiceStatus'     => 'service_status',
        'PlanID'            => 'plan_id',
        'ServiceType'       => 'service_type',
        'ServiceScope'      => 'service_scope',
        'OrderType'         => 'order_type',
        'CustomerName'      => 'customer_name',
        'Phone'             => 'phone',
        'NBNInstanceID'     => 'nbn_instance_id',
        'AVCID'             => 'avc_id',
        'CVCID'             => 'cvc_id',
        'DirectoryID'       => 'directory_id',
        'CopperPairID'      => 'copper_pair_id',
        'NTDID'             => 'ntd_id',
        'DataPortID'        => 'uni_port',
        'ServiceLevel'      => 'service_level',
        'LocationReference' => 'location_reference',
        'BillingProviderID' => 'billing_provider_id',
        'Username'          => 'username',
        'AuthType'          => 'auth_type',
        'IPAssignment'      => 'ip_assignment',
        'IPAddress'         => 'ip_address',
        'Reference'         => 'reference',
        'CentralSplitter'   => 'central_splitter',
    ];

    /**
     * Copy a Get SERVICE response onto this model (not saved). Missing params keep
     * the stored value. The PPPoE Username (2..100 chars, user@realm) goes to
     * `username`; `realm` only receives the realm part, never the whole Username.
     *
     * @param array<string,mixed> $params
     */
    public function applyLiveParams(array $params): static
    {
        foreach (self::LIVE_FIELD_MAP as $param => $column) {
            if (isset($params[$param]) && is_scalar($params[$param]) && $params[$param] !== '') {
                $value = (string) $params[$param];
                $this->{$column} = isset(self::LIMITS[$column]) ? mb_substr($value, 0, self::LIMITS[$column]) : $value;
            }
        }

        $realm = $params['Realm'] ?? (isset($params['Username']) && is_string($params['Username']) && str_contains($params['Username'], '@')
            ? substr($params['Username'], strpos($params['Username'], '@') + 1)
            : null);
        if (is_string($realm) && $realm !== '') {
            $this->realm = mb_substr($realm, 0, self::LIMITS['realm']);
        }

        $this->synced_at = now();

        return $this;
    }

    /**
     * Layer 2 (NCL2 / Network-Connect) services. The Examples (4.2) sample orders NCL2
     * with scope RESELLER-CONNECT and a plan like "NCL2-DSCP-MAP", so the plan id counts too.
     */
    public function isLayer2(): bool
    {
        return strtoupper((string) $this->service_scope) === 'NETWORK-CONNECT'
            || str_starts_with(strtoupper((string) $this->plan_id), 'NCL2');
    }

    /** Suspend / Resume are "only valid for Layer 3 / Reseller-Connect" (Examples 4.1.9). */
    public function supportsSuspendResume(): bool
    {
        return ! $this->isLayer2();
    }

    /** Status changes are refused while a transaction is in flight or the service is still PROCESSING. */
    public function canChangeStatus(): bool
    {
        return ! $this->isPending() && strtoupper((string) $this->service_status) !== 'PROCESSING';
    }

    public function isPollCoolingDown(): bool
    {
        $seconds = (int) config('services.vocus.poll_cooldown_seconds', 300);

        return $seconds > 0 && $this->last_polled_at !== null && $this->last_polled_at->gt(now()->subSeconds($seconds));
    }

    public function hasTransactionFault(): bool
    {
        return $this->last_transaction_state === 'FAILED' && ($this->last_fault_code || $this->last_fault_message);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function vocusPlan()
    {
        return $this->belongsTo(VocusPlan::class);
    }

    /** sell - cost, or null while either side of the snapshot is missing. Admin-only: never show to clients. */
    public function getMarginAttribute(): ?string
    {
        if ($this->cost_price === null || $this->sell_price === null) {
            return null;
        }

        return number_format((float) $this->sell_price - (float) $this->cost_price, 2, '.', '');
    }

    public function diagnostics()
    {
        return $this->hasMany(InternetServiceDiagnostic::class)->latest('created_at');
    }

    public function getStatusBadgeColorAttribute(): string
    {
        return match ($this->service_status) {
            'ACTIVE'   => 'green',
            'SUSPEND'  => 'yellow',
            'INACTIVE' => 'red',
            default    => 'gray',
        };
    }

    public function isPending(): bool
    {
        return in_array($this->last_transaction_state, ['QUEUED', 'PROCESSING']);
    }
}
