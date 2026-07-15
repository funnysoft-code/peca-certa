<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class IdentifyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'request' => ['required', 'string', 'max:500'],
            'vin' => ['required', 'string', 'min:11', 'max:17'],
        ];
    }

    public function requestText(): string
    {
        $value = $this->validated('request');

        return is_string($value) ? $value : '';
    }

    public function vin(): string
    {
        $value = $this->validated('vin');

        return is_string($value) ? $value : '';
    }
}
