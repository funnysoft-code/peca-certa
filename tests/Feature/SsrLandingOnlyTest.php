<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Ssr\HttpGateway;

/**
 * SSR is only for the public landing (`home`). Drive the real gateway
 * condition registered in AppServiceProvider.
 */
function ssrIsEnabledForCurrentRequest(): bool
{
    $gateway = resolve(HttpGateway::class);
    $method = new ReflectionMethod(HttpGateway::class, 'ssrIsEnabled');

    return $method->invoke($gateway, request());
}

test('ssr is enabled on the public landing gate', function (): void {
    $this->get(route('home'))->assertOk();

    expect(ssrIsEnabledForCurrentRequest())->toBeTrue();
});

test('ssr is disabled on the login page', function (): void {
    $this->get(route('login'))->assertOk();

    expect(ssrIsEnabledForCurrentRequest())->toBeFalse();
});

test('ssr is disabled on authenticated identify home', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('identify.create'))->assertOk();

    expect(ssrIsEnabledForCurrentRequest())->toBeFalse();
});

test('ssr is disabled on parts', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('parts.index'))->assertOk();

    expect(ssrIsEnabledForCurrentRequest())->toBeFalse();
});

test('ssr is disabled on settings', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('profile.edit'))->assertOk();

    expect(ssrIsEnabledForCurrentRequest())->toBeFalse();
});
