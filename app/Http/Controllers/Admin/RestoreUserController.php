<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\RestoreUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

final class RestoreUserController extends Controller
{
    public function __invoke(User $user, RestoreUser $restoreUser): RedirectResponse
    {
        $this->authorize('restore', $user);

        $restoreUser->execute($user);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Utilizador restaurado.',
        ]);

        return to_route('admin.users.index', ['status' => 'all']);
    }
}
