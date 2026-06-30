<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Redis;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        if (! defined('LARAVEL_START')) {
            define('LARAVEL_START', microtime(true));
        }

        parent::setUp();

        $this->namespaceRedisKeysForParallelIsolation();
    }

    /**
     * Parallel Pest workers share the same Redis instance, so unprefixed
     * keys collide (one worker's FLUSHDB wipes another's fixture state).
     * Inject a per-worker prefix sourced from the TEST_TOKEN env var so
     * every key written via Laravel's Redis facade lands in an isolated
     * namespace.
     *
     * This is a no-op when Redis is not used in the application (the
     * facade call never fires) and when TEST_TOKEN is unset (serial runs).
     */
    private function namespaceRedisKeysForParallelIsolation(): void
    {
        $token = getenv('TEST_TOKEN');

        if ($token === false || $token === '') {
            return;
        }

        $existing = config('database.redis.options.prefix', '');
        $suffix = sprintf('test_%s:', $token);

        if (str_contains((string) $existing, $suffix)) {
            return;
        }

        config(['database.redis.options.prefix' => $existing.$suffix]);
        Redis::purge();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? sprintf('Fortify feature [%s] is not enabled.', $feature));
        }
    }

    /**
     * Parallel-safe alternative to `Redis::flushdb()`.
     *
     * `FLUSHDB` ignores the per-worker prefix injected above and wipes the
     * whole Redis database — under `--parallel` this nukes fixture state
     * written by sibling workers mid-test, surfacing as "Redis race" flakes.
     *
     * We temporarily clear OPT_PREFIX on the phpredis client so SCAN returns
     * raw keys and DEL accepts them verbatim — then restore the prefix in a
     * `finally` so subsequent test calls stay namespaced.
     * Falls back to `flushdb()` when no prefix is set (serial runs).
     */
    protected function flushRedisForCurrentWorker(): void
    {
        $configured = config('database.redis.options.prefix', '');
        $prefix = is_string($configured) ? $configured : '';

        if ($prefix === '') {
            Redis::flushdb();

            return;
        }

        /** @var \Redis $client */
        $client = Redis::connection()->client();

        $client->setOption(\Redis::OPT_PREFIX, '');

        try {
            $cursor = null;
            do {
                /** @var list<string>|false $keys */
                $keys = $client->scan($cursor, $prefix.'*', 1000);
                if ($keys === false) {
                    continue;
                }

                if ($keys === []) {
                    continue;
                }

                $client->del($keys);
            } while ($cursor > 0);
        } finally {
            $client->setOption(\Redis::OPT_PREFIX, $prefix);
        }
    }
}
