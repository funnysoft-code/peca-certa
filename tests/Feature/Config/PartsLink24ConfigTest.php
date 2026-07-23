<?php

declare(strict_types=1);

it('exposes partslink24 catalog config and a wmi brand map', function (): void {
    expect(config('suppliers.partslink24.lang'))->toBe('en')
        ->and(config('suppliers.partslink24.max_candidates'))->toBeInt()
        ->and(config('suppliers.partslink24.brands.wmi.WMW'))->toBe('mini')
        ->and(config('suppliers.partslink24.brands.wmi.VXK'))->toBe('opel')
        ->and(config('suppliers.partslink24.brands.wmi.W0S'))->toBe('opel')
        ->and(config('suppliers.partslink24.brands.wmi.WMA'))->toBe('man')
        ->and(config('suppliers.partslink24.brands.catalogs.mini'))
        ->toBe(['service' => 'mini_parts', 'group' => 'p5bmw'])
        ->and(config('suppliers.partslink24.brands.catalogs.opel'))
        ->toBe(['service' => 'psa_opel_parts', 'group' => 'p5psa'])
        ->and(config('suppliers.partslink24.brands.catalogs.man'))
        ->toBe(['service' => 'man_parts', 'group' => 'p5man']);
});
