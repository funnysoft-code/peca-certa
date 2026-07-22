<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

final readonly class KeysIn implements ValidationRule
{
    /**
     * @param  list<string>  $values
     */
    public function __construct(
        private array $values,
    ) {}

    /**
     * @param  Closure(string, string|null=):PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail('The :attribute must be an array.');

            return;
        }

        /** @var array<array-key, mixed> $value */
        $allowedKeys = array_flip($this->values);
        $unknownKeys = array_diff_key($value, $allowedKeys);

        if ($unknownKeys === []) {
            return;
        }

        $fail('The selected :attribute key is invalid. Valid keys are: '.implode(', ', $this->values));
    }
}
