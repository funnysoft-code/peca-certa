<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\InviteUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

final class ResendUserInviteController extends Controller
{
    public function __invoke(User $user, InviteUser $inviteUser): RedirectResponse
    {
        $this->authorize('resendInvite', $user);

        $inviteUser->resend($user);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Convite reenviado.',
        ]);

        return to_route('admin.users.index');
    }
}
