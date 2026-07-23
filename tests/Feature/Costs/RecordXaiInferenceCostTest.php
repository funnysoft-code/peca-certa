<?php

declare(strict_types=1);

use App\Actions\RecordXaiInferenceCost;
use App\Listeners\CaptureXaiInferenceCost;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $ledger = storage_path('app/private/costs/xai-usage-test.jsonl');
    config(['costs.xai_ledger' => $ledger]);

    if (File::exists($ledger)) {
        File::delete($ledger);
    }
});

it('appends inference cost ticks to the jsonl ledger', function (): void {
    resolve(RecordXaiInferenceCost::class)->execute([
        'cost_in_usd_ticks' => 10_000_000_000,
        'model' => 'grok-4.3',
        'path' => '/v1/chat/completions',
        'prompt_tokens' => 10,
        'completion_tokens' => 2,
        'total_tokens' => 12,
        'recorded_at' => '2026-07-23T12:00:00+00:00',
    ]);

    $path = (string) config('costs.xai_ledger');
    expect(File::exists($path))->toBeTrue();

    $line = mb_trim((string) File::get($path));
    $row = json_decode($line, true);

    expect($row['cost_in_usd_ticks'])->toBe(10_000_000_000)
        ->and((float) $row['usd'])->toBe(1.0)
        ->and($row['model'])->toBe('grok-4.3')
        ->and($row['path'])->toBe('/v1/chat/completions');
});

it('ignores payloads without cost ticks', function (): void {
    resolve(RecordXaiInferenceCost::class)->execute([
        'model' => 'grok-4.3',
    ]);

    expect(File::exists((string) config('costs.xai_ledger')))->toBeFalse();
});

it('records cost_in_usd_ticks from api.x.ai responses via CaptureXaiInferenceCost', function (): void {
    $body = json_encode([
        'id' => 'chatcmpl-test',
        'model' => 'grok-4.3',
        'usage' => [
            'prompt_tokens' => 5,
            'completion_tokens' => 1,
            'total_tokens' => 6,
            'cost_in_usd_ticks' => 5_000_000_000,
        ],
    ], JSON_THROW_ON_ERROR);

    $event = new ResponseReceived(
        new Request(new PsrRequest('POST', 'https://api.x.ai/v1/chat/completions')),
        new Response(new PsrResponse(200, ['Content-Type' => 'application/json'], $body)),
    );

    resolve(CaptureXaiInferenceCost::class)->handle($event);

    $path = (string) config('costs.xai_ledger');
    expect(File::exists($path))->toBeTrue();

    $row = json_decode(mb_trim((string) File::get($path)), true);

    expect($row['cost_in_usd_ticks'])->toBe(5_000_000_000)
        ->and((float) $row['usd'])->toBe(0.5)
        ->and($row['model'])->toBe('grok-4.3');
});

it('ignores negative cost ticks', function (): void {
    resolve(RecordXaiInferenceCost::class)->execute([
        'cost_in_usd_ticks' => -1,
        'model' => 'grok-4.3',
    ]);

    expect(File::exists((string) config('costs.xai_ledger')))->toBeFalse();
});

it('creates the ledger directory when missing', function (): void {
    $ledger = storage_path('app/private/costs-nested-test/xai-usage.jsonl');
    config(['costs.xai_ledger' => $ledger]);
    File::deleteDirectory(dirname($ledger));

    resolve(RecordXaiInferenceCost::class)->execute([
        'cost_in_usd_ticks' => 1,
        'model' => 'grok-4.3',
    ]);

    expect(File::exists($ledger))->toBeTrue();
    File::deleteDirectory(dirname($ledger));
});

it('skips CaptureXaiInferenceCost for non-xai hosts and invalid payloads', function (): void {
    $listener = resolve(CaptureXaiInferenceCost::class);

    $listener->handle(new ResponseReceived(
        new Request(new PsrRequest('POST', 'https://example.com/v1/chat')),
        new Response(new PsrResponse(200, ['Content-Type' => 'application/json'], '{"ok":true}')),
    ));

    $listener->handle(new ResponseReceived(
        new Request(new PsrRequest('POST', 'https://api.x.ai/v1/chat/completions')),
        new Response(new PsrResponse(200, ['Content-Type' => 'text/plain'], 'not-json')),
    ));

    $listener->handle(new ResponseReceived(
        new Request(new PsrRequest('POST', 'https://api.x.ai/v1/chat/completions')),
        new Response(new PsrResponse(200, ['Content-Type' => 'application/json'], '{"usage":{}}')),
    ));

    $listener->handle(new ResponseReceived(
        new Request(new PsrRequest('POST', 'https://api.x.ai/v1/chat/completions')),
        new Response(new PsrResponse(200, ['Content-Type' => 'application/json'], json_encode([
            'usage' => ['cost_in_usd_ticks' => ['bad']],
        ], JSON_THROW_ON_ERROR))),
    ));

    expect(File::exists((string) config('costs.xai_ledger')))->toBeFalse();
});

it('swallows exceptions from cost accounting so inference is never broken', function (): void {
    config(['costs.xai_ledger' => '/dev/null/not-writable/xai-usage.jsonl']);

    $body = json_encode([
        'model' => 'grok-4.3',
        'usage' => ['cost_in_usd_ticks' => 1],
    ], JSON_THROW_ON_ERROR);

    $event = new ResponseReceived(
        new Request(new PsrRequest('POST', 'https://api.x.ai/v1/chat/completions')),
        new Response(new PsrResponse(200, ['Content-Type' => 'application/json'], $body)),
    );

    expect(fn () => resolve(CaptureXaiInferenceCost::class)->handle($event))
        ->not->toThrow(Throwable::class);
});
