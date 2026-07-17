<?php

declare(strict_types=1);

it('exposes partslink24 catalog config and a wmi brand map', function (): void {
    expect(config('suppliers.partslink24.lang'))->toBe('en')
        ->and(config('suppliers.partslink24.country'))->toBe('PT')
        ->and(config('suppliers.partslink24.max_candidates'))->toBeInt()
        ->and(config('suppliers.partslink24.brands.wmi.WMW'))->toBe('mini')
        ->and(config('suppliers.partslink24.brands.catalogs.mini'))
        ->toBe(['service' => 'mini_parts', 'group' => 'p5bmw']);
});
