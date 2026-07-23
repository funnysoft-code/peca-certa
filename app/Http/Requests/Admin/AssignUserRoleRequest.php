<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\Request;
use App\Support\Permissions;
use Illuminate\Validation\Rule;

final class AssignUserRoleRequest extends Request
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
            'role' => [
                'required',
                'string',
                Rule::in([Permissions::RoleAdmin, Permissions::RoleUser]),
            ],
        ];
    }
}
