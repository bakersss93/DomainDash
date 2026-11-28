<?php

namespace App\Rules;

use App\Support\Security\TrustedWsdl;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** The Synergy WSDL may only be a synergywholesale.com https URL or a file under resources/ or storage/app. */
class TrustedWsdlLocation implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) || ! TrustedWsdl::isTrusted($value)) {
            $fail('The :attribute must be a synergywholesale.com HTTPS URL or a WSDL file inside the application.');
        }
    }
}
