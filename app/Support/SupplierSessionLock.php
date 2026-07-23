<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Canonical WithoutOverlapping keys for supplier portal sessions.
 *
 * Dedicated app logins (F7T-106) removed operator-vs-app contention for most portals,
 * but two accounts still require app-side serialization:
 *
 * - Auto Zitânia: one concurrent portal session for ALL app work (pricing today;
 *   any future plate/VIN identify job MUST take the same lock — never invent a second key).
 * - PartsLink24: dedicated account still self-evicts via squeezeOut on parallel logins.
 *
 * Locks are {@see WithoutOverlapping::shared()} so different job classes serialize together.
 * Expiry is {@see self::ExpiresAfterSeconds} (~job timeout + buffer) so a crashed worker
 * cannot hold the lock forever.
 */
final class SupplierSessionLock
{
    public const string AutoZitania = 'supplier-session:autozitania';

    public const string PartsLink24 = 'supplier-session:partslink24';

    /**
     * Lock expiry in seconds. Must exceed the longest job timeout that uses these keys
     * (IdentifyAgentJob: 120s, PriceSupplierJob Zitânia: 90s, IdentifyOePartsJob: 90s).
     */
    public const int ExpiresAfterSeconds = 150;

    public static function autoZitania(): WithoutOverlapping
    {
        return self::make(self::AutoZitania);
    }

    public static function partsLink24(): WithoutOverlapping
    {
        return self::make(self::PartsLink24);
    }

    private static function make(string $key): WithoutOverlapping
    {
        return new WithoutOverlapping($key)
            ->shared()
            ->expireAfter(self::ExpiresAfterSeconds);
    }
}
