<?php

declare(strict_types=1);

use App\Support\SupplierSessionLock;
use Illuminate\Queue\Middleware\WithoutOverlapping;

it('exposes stable canonical session keys so jobs cannot invent a second mutex', function (): void {
    expect(SupplierSessionLock::AutoZitania)->toBe('supplier-session:autozitania')
        ->and(SupplierSessionLock::PartsLink24)->toBe('supplier-session:partslink24')
        ->and(SupplierSessionLock::ExpiresAfterSeconds)->toBe(150);
});

it('builds shared WithoutOverlapping middleware with expiry past the longest supplier job timeout', function (): void {
    $zitania = SupplierSessionLock::autoZitania();
    $pl24 = SupplierSessionLock::partsLink24();

    expect($zitania)->toBeInstanceOf(WithoutOverlapping::class)
        ->and($zitania->key)->toBe(SupplierSessionLock::AutoZitania)
        ->and($zitania->shareKey)->toBeTrue()
        ->and($zitania->expiresAfter)->toBe(SupplierSessionLock::ExpiresAfterSeconds)
        ->and($zitania->expiresAfter)->toBeGreaterThan(120)
        ->and($pl24->key)->toBe(SupplierSessionLock::PartsLink24)
        ->and($pl24->shareKey)->toBeTrue()
        ->and($pl24->expiresAfter)->toBe(SupplierSessionLock::ExpiresAfterSeconds);
});
