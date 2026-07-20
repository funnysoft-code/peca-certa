<?php

declare(strict_types=1);

use App\Services\PartsLink24\VinBrandResolver;

it('resolves a Mini VIN to the mini catalog', function (): void {
    $brand = resolve(VinBrandResolver::class)->resolve('WMWSU91010T717700');

    expect($brand)->not->toBeNull()
        ->and($brand->key)->toBe('mini')
        ->and($brand->service)->toBe('mini_parts')
        ->and($brand->group)->toBe('p5bmw');
});

it('resolves a BMW VIN via a shared WMI', function (): void {
    expect(resolve(VinBrandResolver::class)->resolve('WBA12345678901234')?->service)->toBe('bmw_parts');
});

it('returns null for an unknown WMI', function (): void {
    expect(resolve(VinBrandResolver::class)->resolve('ZZZ99999999999999'))->toBeNull();
});

it('returns null for a too-short VIN', function (): void {
    expect(resolve(VinBrandResolver::class)->resolve('WM'))->toBeNull();
});

it('returns null when a WMI maps to a brand with no catalog entry', function (): void {
    config(['suppliers.partslink24.brands.wmi.XXX' => 'ghost']);

    expect(resolve(VinBrandResolver::class)->resolve('XXX99999999999999'))->toBeNull();
});
