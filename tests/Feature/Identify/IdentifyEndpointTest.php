<?php

declare(strict_types=1);

use App\Ai\Agents\PartRequestUnderstander;
use App\Models\User;
use App\Services\PartsLink24\Contracts\PartsLink24Catalog;

it('renders the identify page for authed users', function (): void {
    $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
        ->get('/identify')->assertOk();
});

it('requires a vin', function (): void {
    $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
        ->postJson('/identify', ['request' => 'filtro', 'vin' => ''])
        ->assertJsonValidationErrorFor('vin');
});

it('returns understanding + results as json', function (): void {
    PartRequestUnderstander::fake([
        ['category' => 'filtro de óleo', 'searchTerm' => 'oil filter', 'keywords' => ['óleo'], 'clarifyingQuestion' => null, 'confidence' => 0.9],
    ]);
    $this->mock(PartsLink24Catalog::class)->shouldReceive('resolveOeParts')->andReturn([]);

    $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
        ->postJson('/identify', ['request' => 'filtro de óleo', 'vin' => 'WVWZZZ1JZXW000001'])
        ->assertOk()
        ->assertJsonStructure(['understanding' => ['category', 'searchTerm', 'keywords', 'clarifyingQuestion', 'confidence'], 'oeParts', 'autoDeltaResults', 'autoZitaniaResults']);
});
