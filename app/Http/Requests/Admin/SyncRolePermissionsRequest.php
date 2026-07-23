<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\Request;
use App\Support\Permissions;
use Illuminate\Validation\Rule;

final class SyncRolePermissionsRequest extends Request
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
            'permissions' => ['required', 'array'],
            'permissions.*' => [
                'string',
                Rule::in(Permissions::all()),
            ],
        ];
    }
}
