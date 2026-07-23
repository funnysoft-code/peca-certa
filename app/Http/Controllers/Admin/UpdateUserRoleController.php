<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\AssignUserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignUserRoleRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

final class UpdateUserRoleController extends Controller
{
    public function __invoke(
        AssignUserRoleRequest $request,
        User $user,
        AssignUserRole $assignUserRole,
    ): RedirectResponse {
        $this->authorize('updateRole', $user);

        $assignUserRole->execute(
            $this->user($request),
            $user,
            $request->string('role')->toString(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Função atualizada.',
        ]);

        return to_route('admin.users.index');
    }
}
