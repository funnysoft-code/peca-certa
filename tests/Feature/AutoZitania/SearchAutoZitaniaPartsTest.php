<?php

declare(strict_types=1);

use App\Actions\SearchAutoZitaniaParts;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    config()->set('suppliers.autozitania.username', 'user');
    config()->set('suppliers.autozitania.password', 'secret');
    config()->set('suppliers.autozitania.portal_url', 'https://portal.test/');
});

it('maps sidecar output to part variants', function (): void {
    $fixture = (string) file_get_contents(base_path('tests/Fixtures/AutoZitania/search-output.json'));
    Process::fake(['*' => Process::result(output: $fixture)]);

    $result = resolve(SearchAutoZitaniaParts::class)->execute('OC 90');

    expect($result->query)->toBe('OC 90')
        ->and($result->variants)->toHaveCount(6)
        ->and($result->variants[0]->brandName)->toBe('FEBI BILSTEIN')
        ->and($result->variants[0]->traderArticleNumber)->toBe('DF36171')
        ->and($result->variants[0]->articleNumber)->toBe('36171')
        ->and($result->variants[0]->retailPrice)->toBe(34.38)
        ->and($result->variants[0]->purchasePrice)->toBeNull()
        ->and($result->variants[0]->inStock)->toBeFalse()
        ->and($result->variants[5]->inStock)->toBeTrue()
        ->and($result->searchUrl)->toBe('https://portal.test/');

    Process::assertRan(function ($process): bool {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        return str_contains($command, 'bin/zitania-search.ts') && str_contains($command, 'OC 90');
    });
});

it('coerces malformed variant values defensively', function (): void {
    Process::fake(['*' => Process::result(output: json_encode([
        'query' => 'X',
        'variants' => [
            ['brandName' => ['nested'], 'articleNumber' => 12345, 'retailPrice' => 'not-a-number', 'inStock' => 'yes'],
            'not-an-array',
        ],
    ]))]);

    $result = resolve(SearchAutoZitaniaParts::class)->execute('X');

    expect($result->variants)->toHaveCount(1)
        ->and($result->variants[0]->brandName)->toBe('')
        ->and($result->variants[0]->articleNumber)->toBe('12345')
        ->and($result->variants[0]->retailPrice)->toBeNull()
        ->and($result->variants[0]->inStock)->toBeFalse();
});

it('returns empty variants when the sidecar reports none', function (): void {
    Process::fake(['*' => Process::result(output: '{"query":"NOPE","variants":"unexpected"}')]);

    $result = resolve(SearchAutoZitaniaParts::class)->execute('NOPE');

    expect($result->variants)->toBe([]);
});

it('throws when the sidecar exits non-zero', function (): void {
    Process::fake(['*' => Process::result(errorOutput: 'login failed', exitCode: 1)]);

    resolve(SearchAutoZitaniaParts::class)->execute('OC 90');
})->throws(RuntimeException::class, 'Auto Zitania search failed');

it('throws on non-json sidecar output', function (): void {
    Process::fake(['*' => Process::result(output: 'not json at all')]);

    resolve(SearchAutoZitaniaParts::class)->execute('OC 90');
})->throws(RuntimeException::class, 'Unexpected Auto Zitania sidecar output');
