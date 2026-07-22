<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ResumeIdentifyRequest extends FormRequest
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
            'answer' => ['nullable', 'string', 'max:1000'],
            'option' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function answer(): string
    {
        $value = $this->validated('answer');

        return is_string($value) ? mb_trim($value) : '';
    }

    public function option(): ?string
    {
        $value = $this->validated('option');

        if (! is_string($value)) {
            return null;
        }

        $trimmed = mb_trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
