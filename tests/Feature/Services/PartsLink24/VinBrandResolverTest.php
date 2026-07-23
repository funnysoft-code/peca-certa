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

it('resolves Opel Zaragoza VXK prod fixture to psa_opel_parts', function (): void {
    $brand = resolve(VinBrandResolver::class)->resolve('VXKUBYHTKM4025404');

    expect($brand)->not->toBeNull()
        ->and($brand->key)->toBe('opel')
        ->and($brand->service)->toBe('psa_opel_parts')
        ->and($brand->group)->toBe('p5psa');
});

it('resolves Opel W0S Stellantis gap to opel catalog', function (): void {
    expect(resolve(VinBrandResolver::class)->resolve('W0SABCDEF12345678')?->service)
        ->toBe('psa_opel_parts');
});

it('resolves MAN WMA prod fixture to man_parts/p5man', function (): void {
    $brand = resolve(VinBrandResolver::class)->resolve('WMA06XZZ8HM753386');

    expect($brand)->not->toBeNull()
        ->and($brand->key)->toBe('man')
        ->and($brand->service)->toBe('man_parts')
        ->and($brand->group)->toBe('p5man');
});

it('resolves brand override even when WMI is unknown', function (): void {
    $brand = resolve(VinBrandResolver::class)->resolve('ZZZ99999999999999', 'opel');

    expect($brand)->not->toBeNull()
        ->and($brand->key)->toBe('opel')
        ->and($brand->service)->toBe('psa_opel_parts');
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

it('lists configured catalog keys including man and opel', function (): void {
    $keys = resolve(VinBrandResolver::class)->availableBrandKeys();

    expect($keys)->toContain('opel', 'man', 'mini');
});

it('returns PSA family fallbacks excluding the primary brand', function (): void {
    $siblings = resolve(VinBrandResolver::class)->familyFallbackKeys('opel');

    expect($siblings)->toContain('peugeot', 'citroen')
        ->and($siblings)->not->toContain('opel')
        ->and(resolve(VinBrandResolver::class)->familyFallbackKeys('man'))->toBe([]);
});
