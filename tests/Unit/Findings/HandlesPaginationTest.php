<?php

declare(strict_types=1);

use App\Concerns\HandlesPagination;
use Illuminate\Http\Request;

final class PaginationProbeRequest extends Request
{
    use HandlesPagination;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return $this->paginationRules();
    }
}

it('clamps page to at least 1 and falls back for non-numeric page', function (): void {
    $request = PaginationProbeRequest::create('/x', 'GET', ['page' => 0]);
    expect($request->getPage())->toBe(1);

    $request = PaginationProbeRequest::create('/x', 'GET', ['page' => 'nope']);
    expect($request->getPage())->toBe(1);

    $request = PaginationProbeRequest::create('/x', 'GET', ['page' => 3]);
    expect($request->getPage())->toBe(3);
});

it('uses config defaults when per_page is missing or invalid', function (): void {
    config()->set('peca.pagination_size', 15);
    config()->set('peca.max_pagination_size', 50);

    $request = PaginationProbeRequest::create('/x', 'GET', []);
    expect($request->getLimit())->toBe(15);

    $request = PaginationProbeRequest::create('/x', 'GET', ['per_page' => 999]);
    expect($request->getLimit())->toBe(15);

    $request = PaginationProbeRequest::create('/x', 'GET', ['per_page' => 25]);
    expect($request->getLimit())->toBe(25);
});

it('falls back when pagination config is non-numeric', function (): void {
    config()->set('peca.pagination_size', 'oops');
    config()->set('peca.max_pagination_size');

    $request = PaginationProbeRequest::create('/x', 'GET', []);
    expect($request->getLimit())->toBe(25);
});

it('exposes pagination validation rules', function (): void {
    $request = new PaginationProbeRequest;

    expect($request->rules())->toBe([
        'per_page' => ['integer'],
        'page' => ['integer'],
    ]);
});
