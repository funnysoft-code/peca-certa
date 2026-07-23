<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

/**
 * Base controller with a typed `user()` helper for traditional (resourceful,
 * non-invokable) controllers. Invokable controllers should prefer
 * `#[CurrentUser] User $user` parameter injection (see stubs/controller.stub) —
 * this helper exists for the cases where attribute injection is not available.
 */
abstract class Controller
{
    use AuthorizesRequests;

    protected function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
