<?php

declare(strict_types=1);

use Laravel\Fortify\Features;

test('registration feature is disabled', function (): void {
    expect(Features::enabled(Features::registration()))->toBeFalse();
});

test('registration routes are not available', function (): void {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();
});
