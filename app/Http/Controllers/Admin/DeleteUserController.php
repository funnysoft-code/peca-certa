<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\SoftDeleteUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class DeleteUserController extends Controller
{
    public function __invoke(
        Request $request,
        User $user,
        SoftDeleteUser $softDeleteUser,
    ): RedirectResponse {
        $this->authorize('delete', $user);

        $softDeleteUser->execute($this->user($request), $user);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Utilizador removido.',
        ]);

        return to_route('admin.users.index');
    }
}
