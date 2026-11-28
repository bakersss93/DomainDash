<?php

namespace App\Rules;

use App\Support\Security\PublicNetwork;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A literal public IP address (not private, loopback, link-local, CGNAT or reserved). */
class PublicIp implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) || ! filter_var($value, FILTER_VALIDATE_IP) || ! PublicNetwork::isPublic($value)) {
            $fail('The :attribute must be a public IP address.');
        }
    }
}
