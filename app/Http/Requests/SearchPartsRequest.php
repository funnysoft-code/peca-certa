<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SearchPartsRequest extends FormRequest
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
            'reference' => ['required', 'string', 'max:100'],
        ];
    }

    public function reference(): string
    {
        $value = $this->validated('reference');

        return is_string($value) ? $value : '';
    }
}
