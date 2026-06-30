<?php

declare(strict_types=1);

namespace App\Services\AutoDelta;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;

final class AutoDeltaClient
{
    private const string CACHE_KEY = 'autodelta.token';

    public function token(): AutoDeltaToken
    {
        $cached = Cache::get(self::CACHE_KEY);

        if ($cached instanceof AutoDeltaToken && $cached->isValid()) {
            return $cached;
        }

        $token = $this->login();

        Cache::put(self::CACHE_KEY, $token, $token->expiresOn);

        return $token;
    }

    private function login(): AutoDeltaToken
    {
        $response = Http::asJson()
            ->post((string) config('suppliers.autodelta.auth_url'), [
                'username' => (string) config('suppliers.autodelta.username'),
                'password' => (string) config('suppliers.autodelta.password'),
            ])
            ->throw()
            ->json();

        return new AutoDeltaToken(
            apiKey: (string) $response['apiKey'],
            catalogUserId: (string) $response['catalogUserId'],
            expiresOn: Date::parse((string) $response['expiresOn']),
        );
    }
}
