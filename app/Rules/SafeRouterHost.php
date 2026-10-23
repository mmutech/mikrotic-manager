<?php

namespace App\Rules;

use App\Support\RouterHostPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SafeRouterHost implements ValidationRule
{
    public function __construct(private ?RouterHostPolicy $policy = null)
    {
        $this->policy ??= app(RouterHostPolicy::class);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            $fail('Enter a router hostname or IP address.');

            return;
        }

        if (! $this->policy->isAllowed($value)) {
            $fail('The host must resolve only to an approved management network. Public destinations are not permitted.');
        }
    }
}
