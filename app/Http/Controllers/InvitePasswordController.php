<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AcceptInvite;
use App\Http\Requests\AcceptInviteRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

final class InvitePasswordController extends Controller
{
    public function create(Request $request): Response
    {
        $email = $request->string('email')->toString();
        $token = $request->string('token')->toString();

        abort_if($email === '' || $token === '', 404);

        return Inertia::render('auth/set-password', [
            'email' => $email,
            'token' => $token,
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function store(AcceptInviteRequest $request, AcceptInvite $acceptInvite): RedirectResponse
    {
        $user = $acceptInvite->execute(
            $request->string('email')->toString(),
            $request->string('token')->toString(),
            $request->string('password')->toString(),
        );

        Auth::login($user);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Palavra-passe definida. Bem-vindo!',
        ]);

        return to_route('identify.create');
    }
}
