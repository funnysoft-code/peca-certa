<?php

declare(strict_types=1);

use App\Actions\RegisterPartsLink24Catalog;
use App\Services\PartsLink24\DynamicPartsLink24CatalogStore;
use App\Services\PartsLink24\VinBrandResolver;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $path = resolve(DynamicPartsLink24CatalogStore::class)->path();
    File::delete($path);
});

afterEach(function (): void {
    $path = resolve(DynamicPartsLink24CatalogStore::class)->path();
    File::delete($path);
});

it('registers a catalog at runtime without config deploy', function (): void {
    $result = resolve(RegisterPartsLink24Catalog::class)->execute(
        'scania',
        'scania_parts',
        'p5scania',
        ['YS2', 'XLE'],
    );

    expect($result['key'])->toBe('scania')
        ->and($result['service'])->toBe('scania_parts')
        ->and($result['group'])->toBe('p5scania')
        ->and($result['wmis'])->toEqualCanonicalizing(['YS2', 'XLE']);
});

it('resolves VIN via dynamic catalog and WMI without static config', function (): void {
    resolve(RegisterPartsLink24Catalog::class)->execute(
        'scania',
        'scania_parts',
        'p5scania',
        ['YS2'],
    );

    $brand = resolve(VinBrandResolver::class)->resolve('YS2ABCDEF12345678');

    expect($brand)->not->toBeNull()
        ->and($brand->key)->toBe('scania')
        ->and($brand->service)->toBe('scania_parts')
        ->and($brand->group)->toBe('p5scania')
        ->and(resolve(VinBrandResolver::class)->availableBrandKeys())->toContain('scania');
});

it('dynamic catalog overrides static catalog for the same brand key', function (): void {
    resolve(RegisterPartsLink24Catalog::class)->execute(
        'mini',
        'mini_parts_custom',
        'p5bmw_custom',
    );

    $brand = resolve(VinBrandResolver::class)->resolve('WMWSU91010T717700');

    expect($brand?->service)->toBe('mini_parts_custom')
        ->and($brand?->group)->toBe('p5bmw_custom');
});

it('artisan partslink24:register-catalog writes the store', function (): void {
    $this->artisan('partslink24:register-catalog', [
        'key' => 'iveco',
        'service' => 'iveco_parts',
        'group' => 'p5iveco',
        '--wmi' => ['ZCF'],
    ])->assertSuccessful();

    $brand = resolve(VinBrandResolver::class)->resolve('ZCF12345678901234');

    expect($brand?->key)->toBe('iveco')
        ->and($brand?->service)->toBe('iveco_parts');
});

it('artisan rejects empty catalog key', function (): void {
    $this->artisan('partslink24:register-catalog', [
        'key' => '   ',
        'service' => 'x_parts',
        'group' => 'p5x',
    ])->assertFailed();
});

it('ignores corrupt dynamic catalog file and invalid entries', function (): void {
    $path = resolve(DynamicPartsLink24CatalogStore::class)->path();
    File::deleteDirectory(dirname($path));
    File::ensureDirectoryExists(dirname($path));
    File::put($path, 'not-json');

    expect(resolve(DynamicPartsLink24CatalogStore::class)->read())
        ->toBe(['catalogs' => [], 'wmi' => []]);

    File::put($path, json_encode([
        'catalogs' => [
            'ok' => ['service' => 'ok_parts', 'group' => 'p5ok'],
            12 => ['service' => 'bad', 'group' => 'p5bad'],
            'missing' => ['service' => '', 'group' => 'p5x'],
            'not_array' => 'skip-me',
        ],
        'wmi' => [
            'AAA' => 'ok',
            '' => 'nope',
            'BB' => '',
        ],
    ], JSON_THROW_ON_ERROR));

    $store = resolve(DynamicPartsLink24CatalogStore::class)->read();

    expect($store['catalogs'])->toHaveKey('ok')
        ->and($store['catalogs'])->not->toHaveKey('missing')
        ->and($store['catalogs'])->not->toHaveKey('not_array')
        ->and($store['wmi'])->toBe(['AAA' => 'ok']);
});

it('creates the dynamic catalog directory when missing on write', function (): void {
    $path = resolve(DynamicPartsLink24CatalogStore::class)->path();
    File::deleteDirectory(dirname($path));

    resolve(RegisterPartsLink24Catalog::class)->execute('tmp', 'tmp_parts', 'p5tmp');

    expect(File::exists($path))->toBeTrue();
});
