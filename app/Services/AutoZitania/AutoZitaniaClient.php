<?php

declare(strict_types=1);

namespace App\Services\AutoZitania;

use Illuminate\Support\Facades\Process;
use RuntimeException;

final readonly class AutoZitaniaClient
{
    /**
     * Run the Playwright sidecar (bin/zitania-search.ts) against the DVSE
     * portal and return the raw variant rows from its JSON stdout.
     *
     * @return list<array<mixed, mixed>>
     */
    public function searchByNumber(string $reference): array
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

        $decoded = json_decode($result->output(), true);

        throw_unless(is_array($decoded), RuntimeException::class, 'Unexpected Auto Zitania sidecar output.');

        $variants = $decoded['variants'] ?? null;

        if (! is_array($variants)) {
            return [];
        }

        return array_values(array_filter($variants, is_array(...)));
    }
}
