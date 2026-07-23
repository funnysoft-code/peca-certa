<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\InviteUserNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Password;

test('invite notification builds branded mail message', function (): void {
    $user = User::factory()->pendingInvite()->create([
        'name' => 'Mail Guest',
        'email' => 'mailguest@example.com',
    ]);
    $token = Password::broker()->createToken($user);

    $notification = new InviteUserNotification($token);
    $mail = $notification->toMail($user);

    expect($mail)->toBeInstanceOf(MailMessage::class)
        ->and($notification->via($user))->toBe(['mail']);

    $rendered = $mail->render();

    expect($rendered)->toContain('Mail Guest')
        ->and($rendered)->toContain('Definir palavra-passe')
        ->and($rendered)->toContain('#4eb8a4');
});
