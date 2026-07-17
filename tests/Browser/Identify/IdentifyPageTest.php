<?php

declare(strict_types=1);

use App\Data\OePart;
use App\Data\PartRequestUnderstanding;
use App\Data\PartSearchResult;
use App\Data\PartVariant;
use App\Enums\SearchRunStatus;
use App\Enums\Supplier;
use App\Enums\SupplierLookupStatus;
use App\Models\SearchRun;
use App\Models\SupplierLookup;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

it('renders the persisted results of a completed run', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $understanding = new PartRequestUnderstanding(
        category: 'filtro de óleo',
        searchTerm: 'oil filter',
        keywords: ['óleo'],
        clarifyingQuestion: null,
        confidence: 0.9,
    );
    $oePart = new OePart('OC 90', 'Filtro de óleo', 'OE');

    $run = SearchRun::factory()->for($user)->create([
        'status' => SearchRunStatus::Done,
        'understanding' => $understanding->jsonSerialize(),
        'oe_parts' => [$oePart->jsonSerialize()],
    ]);

    $availableVariant = new PartVariant(
        brandName: 'MANN-FILTER',
        articleNumber: 'W 712/75',
        traderArticleNumber: 'W71275',
        purchasePrice: 4.5,
        retailPrice: 7.9,
        currency: 'EUR',
        availableQuantity: 12,
        inStock: true,
        warehouse: 'Lisboa',
    );

    SupplierLookup::factory()->for($run, 'run')->create([
        'supplier' => Supplier::AutoDelta,
        'query' => $oePart->oeNumber,
        'oe_description' => $oePart->description,
        'status' => SupplierLookupStatus::Done,
        'result' => new PartSearchResult(
            query: $oePart->oeNumber,
            variants: [$availableVariant],
            searchUrl: 'https://web.tecalliance.net/search',
        )->jsonSerialize(),
    ]);

    SupplierLookup::factory()->for($run, 'run')->create([
        'supplier' => Supplier::AutoZitania,
        'query' => $oePart->oeNumber,
        'oe_description' => $oePart->description,
        'status' => SupplierLookupStatus::Empty,
        'result' => new PartSearchResult(
            query: $oePart->oeNumber,
            variants: [],
        )->jsonSerialize(),
    ]);

    $this->actingAs($user);

    $page = visit('/identify/'.$run->id);
    $page->waitForEvent('networkidle');

    $page->assertSee('OC 90')
        ->assertSee('Fornecedor')
        ->assertSee('4.50')
        ->assertSee('Abrir em Auto Delta')
        ->assertPresent('a[href="https://web.tecalliance.net/search"]');
});

it('submits the identify form and lands on the run page', function (): void {
    Bus::fake();

    $this->actingAs(User::factory()->create(['email_verified_at' => now()]));

    $page = visit('/identify');
    $page->waitForEvent('networkidle');

    $page->fill('input[placeholder="Pedido do cliente"]', 'filtro de óleo para Golf')
        ->fill('input[placeholder="VIN"]', 'WVWZZZ1JZXW000001')
        ->press('Identificar');

    $page->waitForEvent('networkidle');

    $page->assertPathBeginsWith('/identify/');

    $run = SearchRun::query()->firstOrFail();

    expect($run->request_text)->toBe('filtro de óleo para Golf')
        ->and($run->vin)->toBe('WVWZZZ1JZXW000001');
});
