<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Supplier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SearchPartsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'max:100'],
            'supplier' => ['required', Rule::enum(Supplier::class)],
        ];
    }

    public function reference(): string
    {
        $value = $this->validated('reference');

        return is_string($value) ? $value : '';
    }

    public function supplier(): Supplier
    {
        $value = $this->validated('supplier');

        return Supplier::from(is_string($value) ? $value : '');
    }
}
