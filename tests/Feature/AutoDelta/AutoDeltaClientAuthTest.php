<?php

declare(strict_types=1);

use App\Services\AutoDelta\AutoDeltaClient;
use App\Services\AutoDelta\AutoDeltaToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('logs in once and caches the token until expiry', function (): void {
    config()->set('suppliers.autodelta.auth_url', 'https://auth.test/AuthWS');
    config()->set('suppliers.autodelta.catalog_id', 'CAT123');
    config()->set('suppliers.autodelta.username', 'u');
    config()->set('suppliers.autodelta.password', 'p');

    Http::fake([
        'auth.test/*' => Http::response([
            'apiKey' => 'KEY-1',
            'catalogUserId' => 'USER-1',
            'expiresOn' => now()->addDay()->toIso8601String(),
            'status' => 200,
        ]),
    ]);

    $client = resolve(AutoDeltaClient::class);

    $first = $client->token();
    $second = $client->token();

    expect($first->apiKey)->toBe('KEY-1')
        ->and($first->catalogUserId)->toBe('USER-1')
        ->and($second->apiKey)->toBe('KEY-1');

    Http::assertSentCount(1); // cached: only one login
});

it('re-logs in when the cached token has expired', function (): void {
    config()->set('suppliers.autodelta.auth_url', 'https://auth.test/AuthWS');
    config()->set('suppliers.autodelta.username', 'u');
    config()->set('suppliers.autodelta.password', 'p');
    config()->set('suppliers.autodelta.catalog_id', 'CAT123');

    Cache::put('autodelta.token', new AutoDeltaToken('OLD', 'USER-OLD', now()->subMinute()), now()->addDay());

    Http::fake(['auth.test/*' => Http::response([
        'apiKey' => 'KEY-2', 'catalogUserId' => 'USER-2',
        'expiresOn' => now()->addDay()->toIso8601String(), 'status' => 200,
    ])]);

    expect(resolve(AutoDeltaClient::class)->token()->apiKey)->toBe('KEY-2');
    Http::assertSentCount(1);
});
