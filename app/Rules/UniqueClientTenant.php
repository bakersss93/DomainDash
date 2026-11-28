<?php

namespace App\Rules;

use App\Models\Client;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** One Microsoft tenant belongs to exactly one client (case-insensitive GUID comparison). */
class UniqueClientTenant implements ValidationRule
{
    public function __construct(private readonly ?int $ignoreClientId = null)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $tenant = strtolower(trim((string) $value));
        if ($tenant === '') {
            return;
        }

        $taken = Client::query()
            ->whereRaw('lower(microsoft_tenant_id) = ?', [$tenant])
            ->when($this->ignoreClientId, fn ($q) => $q->where('id', '!=', $this->ignoreClientId))
            ->exists();

        if ($taken) {
            $fail('That Microsoft tenant is already assigned to another client.');
        }
    }
}
