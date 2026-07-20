<?php

declare(strict_types=1);

use App\Actions\UnderstandPartRequest;
use App\Ai\Agents\PartRequestUnderstander;

it('structures a clear request into category and keywords', function (): void {
    PartRequestUnderstander::fake([
        ['category' => 'filtro de óleo', 'searchTerm' => 'oil filter', 'keywords' => ['filtro', 'óleo'], 'clarifyingQuestion' => null, 'confidence' => 0.92],
    ]);

    $result = resolve(UnderstandPartRequest::class)->execute('filtro de óleo para Golf 1.9 TDI');

    expect($result->category)->toBe('filtro de óleo')
        ->and($result->searchTerm)->toBe('oil filter')
        ->and($result->keywords)->toBe(['filtro', 'óleo'])
        ->and($result->needsClarification())->toBeFalse();
});

it('returns a clarifying question when the request is ambiguous', function (): void {
    PartRequestUnderstander::fake([
        ['category' => '', 'searchTerm' => '', 'keywords' => [], 'clarifyingQuestion' => 'Qual é o motor da viatura?', 'confidence' => 0.2],
    ]);

    $result = resolve(UnderstandPartRequest::class)->execute('preciso de uma peça');

    expect($result->needsClarification())->toBeTrue()
        ->and($result->clarifyingQuestion)->toBe('Qual é o motor da viatura?');
});

it('defensively coerces a malformed structured response', function (): void {
    PartRequestUnderstander::fake([
        ['category' => null, 'searchTerm' => null, 'keywords' => 'não é uma lista', 'clarifyingQuestion' => '', 'confidence' => '0.5'],
    ]);

    $result = resolve(UnderstandPartRequest::class)->execute('pedido estranho');

    expect($result->category)->toBe('')
        ->and($result->searchTerm)->toBe('')
        ->and($result->keywords)->toBe([])
        ->and($result->clarifyingQuestion)->toBeNull()
        ->and($result->confidence)->toBe(0.5);
});

it('falls back the search term to the category when the model omits it', function (): void {
    PartRequestUnderstander::fake([
        ['category' => 'filtro de óleo', 'searchTerm' => '', 'keywords' => ['óleo'], 'clarifyingQuestion' => null, 'confidence' => 0.9],
    ]);

    $result = resolve(UnderstandPartRequest::class)->execute('filtro de óleo');

    expect($result->searchTerm)->toBe('filtro de óleo');
});
