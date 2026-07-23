<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\Request;
use App\Models\User;
use Illuminate\Validation\Rule;

final class InviteUserRequest extends Request
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                // Soft-deleted users free the email (partial unique index).
                Rule::unique(User::class, 'email')->whereNull('deleted_at'),
            ],
        ];
    }
}
