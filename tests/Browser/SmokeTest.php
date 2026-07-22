<?php

declare(strict_types=1);

use App\Models\User;

// -------------------------------------------------------------------------
// Smoke tests — assert no JavaScript errors, no unhandled exceptions, and
// no 4xx/5xx HTTP responses on the critical page set.
//
// Run serially (Browser suite is excluded from --parallel by convention).
// -------------------------------------------------------------------------

test('guest pages have no smoke', function (): void {
    visit([
        '/',
        '/login',
        '/forgot-password',
        '/two-factor-challenge',
    ])->assertNoSmoke();
});

test('authenticated pages have no smoke', function (): void {
    $user = User::factory()->withoutTwoFactor()->create();
    $this->actingAs($user);

    visit([
        '/identify',
        '/parts',
        '/analytics',
        '/settings/profile',
        '/settings/security',
        '/settings/appearance',
    ])->assertNoSmoke();
});

// -------------------------------------------------------------------------
// Canonical interactive login test
//
// This test demonstrates the required invariant for all interactive browser
// tests: waitForEvent('networkidle') BEFORE any fill() / press() call.
// Inertia apps perform a full XHR boot after the page shell loads; skipping
// the networkidle wait leads to intermittent "element not attached" errors.
// -------------------------------------------------------------------------

test('user can log in interactively', function (): void {
    $user = User::factory()->withoutTwoFactor()->create();

    $page = visit('/login');

    // Wait for Inertia's initial XHR hydration to complete before interacting.
    $page->waitForEvent('networkidle');

    $page->assertSee('Entrar')
        ->fill('#email', $user->email)
        ->fill('#password', 'password')
        ->press('@login-button')
        ->assertPathIs('/identify')
        ->assertNoJavaScriptErrors();

    $this->assertAuthenticated();
});
