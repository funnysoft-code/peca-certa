<?php

declare(strict_types=1);

namespace App\Services\PartsLink24;

use Carbon\CarbonInterface;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;

final readonly class PartsLink24Token
{
    /**
     * @param  list<array<string, mixed>>  $cookies  Serialized Guzzle cookie arrays from login/warm-up.
     */
    public function __construct(
        public string $accessToken,
        public CarbonInterface $expiresAt,
        public array $cookies = [],
    ) {}

    public function isValid(): bool
    {
        return $this->expiresAt->isFuture();
    }

    public function cookieJar(): CookieJar
    {
        $jar = new CookieJar;

        foreach ($this->cookies as $cookie) {
            $jar->setCookie(new SetCookie($cookie));
        }

        return $jar;
    }
}
