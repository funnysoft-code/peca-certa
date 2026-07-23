<?php

declare(strict_types=1);

use App\Actions\PersistLookupFindings;
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

    $autoDeltaLookup = SupplierLookup::factory()->for($run, 'run')->create([
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

    resolve(PersistLookupFindings::class)->execute($autoDeltaLookup->refresh());

    $this->actingAs($user);

    $page = visit('/identify/'.$run->id);
    $page->waitForEvent('networkidle');

    $page->assertSee('OC 90')
        ->assertSee('Fornecedor')
        ->assertSee('4.50')
        ->assertSee('Abrir OC 90 em Auto Delta')
        ->assertPresent('a[href="https://web.tecalliance.net/search"]');
});

it('shows first-supplier findings while another supplier is still pending', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $understanding = new PartRequestUnderstanding(
        category: 'manete de mudanças',
        searchTerm: 'gear shift lever',
        keywords: ['manete'],
        clarifyingQuestion: null,
        confidence: 0.9,
    );
    $oePart = new OePart('25112753810', '[Gear] [shift] lug', 'OE');

    $run = SearchRun::factory()->for($user)->create([
        'status' => SearchRunStatus::Running,
        'request_text' => 'manete de mudanças',
        'understanding' => $understanding->jsonSerialize(),
        'oe_parts' => [$oePart->jsonSerialize()],
    ]);

    $availableVariant = new PartVariant(
        brandName: 'LEMFORDER',
        articleNumber: '25112753810',
        traderArticleNumber: 'L2511',
        purchasePrice: 42.1,
        retailPrice: 69.9,
        currency: 'EUR',
        availableQuantity: 3,
        inStock: true,
        warehouse: 'Porto',
    );

    $autoDeltaLookup = SupplierLookup::factory()->for($run, 'run')->create([
        'supplier' => Supplier::AutoDelta,
        'query' => $oePart->oeNumber,
        'oe_description' => $oePart->description,
        'status' => SupplierLookupStatus::Done,
        'result' => new PartSearchResult(
            query: $oePart->oeNumber,
            variants: [$availableVariant],
            searchUrl: 'https://web.tecalliance.net/search?q=25112753810',
        )->jsonSerialize(),
    ]);

    SupplierLookup::factory()->for($run, 'run')->create([
        'supplier' => Supplier::AutoZitania,
        'query' => $oePart->oeNumber,
        'oe_description' => $oePart->description,
        'status' => SupplierLookupStatus::Running,
        'result' => null,
    ]);

    resolve(PersistLookupFindings::class)->execute($autoDeltaLookup->refresh());

    $this->actingAs($user);

    $page = visit('/identify/'.$run->id);
    $page->waitForEvent('networkidle');

    // Progressive UI: first supplier rows must paint while Zitânia is still running.
    // Regression: skeleton used to stay up until every supplier finished.
    $page->assertSee('A pesquisar em Auto Zitânia')
        ->assertSee('LEMFORDER')
        ->assertSee('42.10')
        ->assertSee('Abrir 25112753810 em Auto Delta')
        ->assertDontSee('A carregar resultados…');
});

it('submits the identify form and lands on the run page', function (): void {
    Bus::fake();

    $this->actingAs(User::factory()->create(['email_verified_at' => now()]));

    $page = visit('/identify');
    $page->waitForEvent('networkidle');

    $page->fill('#request', 'filtro de óleo para Golf')
        ->fill('#vin', 'WVWZZZ1JZXW000001')
        ->click('form:has(#request) button[type="submit"]');

    $page->waitForEvent('networkidle');

    $page->assertPathBeginsWith('/identify/');

    $run = SearchRun::query()->firstOrFail();

    expect($run->request_text)->toBe('filtro de óleo para Golf')
        ->and($run->vin)->toBe('WVWZZZ1JZXW000001');
});
