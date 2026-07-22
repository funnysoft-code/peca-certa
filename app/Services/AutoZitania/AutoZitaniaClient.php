<?php

declare(strict_types=1);

namespace App\Services\AutoZitania;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use RuntimeException;

final readonly class AutoZitaniaClient
{
    /**
     * Search Auto Zitania for a part reference.
     *
     * Local/default: Bun + Playwright sidecar (`bin/zitania-search.ts`).
     * Production: HTTP to the Cloudflare Browser Rendering Worker
     * (`workers/zitania-browser`) when `suppliers.autozitania.http_url` is set.
     *
     * @return list<array<mixed, mixed>>
     */
    public function searchByNumber(string $reference): array
    {
        $httpUrl = config()->string('suppliers.autozitania.http_url');

        return $httpUrl !== ''
            ? $this->searchViaHttp($reference, $httpUrl)
            : $this->searchViaProcess($reference);
    }

    /**
     * @return list<array<mixed, mixed>>
     */
    private function searchViaHttp(string $reference, string $httpUrl): array
    {
        $token = config()->string('suppliers.autozitania.http_token');

        try {
            $response = Http::timeout(config()->integer('suppliers.autozitania.script_timeout'))
                ->acceptJson()
                ->when($token !== '', fn ($pending) => $pending->withToken($token))
                ->post($httpUrl, ['reference' => $reference])
                ->throw();
        } catch (ConnectionException|RequestException $e) {
            throw new RuntimeException('Auto Zitania search failed: '.$e->getMessage(), $e->getCode(), previous: $e);
        }

        return $this->decodeVariants($response->body());
    }

    /**
     * @return list<array<mixed, mixed>>
     */
    private function searchViaProcess(string $reference): array
    {
        $result = Process::timeout(config()->integer('suppliers.autozitania.script_timeout'))
            ->path(base_path())
            ->env([
                'AUTOZITANIA_USERNAME' => config()->string('suppliers.autozitania.username'),
                'AUTOZITANIA_PASSWORD' => config()->string('suppliers.autozitania.password'),
                'AUTOZITANIA_ENTRY_URL' => config()->string('suppliers.autozitania.entry_url'),
            ])
            ->run([config()->string('suppliers.autozitania.bun_binary'), 'bin/zitania-search.ts', $reference]);

        throw_if($result->failed(), RuntimeException::class, 'Auto Zitania search failed: '.$result->errorOutput());

        return $this->decodeVariants($result->output());
    }

    /**
     * @return list<array<mixed, mixed>>
     */
    private function decodeVariants(string $payload): array
    {
        $decoded = json_decode($payload, true);

        throw_unless(is_array($decoded), RuntimeException::class, 'Unexpected Auto Zitania sidecar output.');

        $variants = $decoded['variants'] ?? null;

        if (! is_array($variants)) {
            return [];
        }

        return array_values(array_filter($variants, is_array(...)));
    }
}
