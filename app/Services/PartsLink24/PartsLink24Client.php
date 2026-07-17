<?php

declare(strict_types=1);

namespace App\Services\PartsLink24;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class PartsLink24Client
{
    /** @var list<string> */
    private const array BASE_SERVICES = ['cart', 'pl24-full-vin-data', 'pl24-orderbridge', 'pl24-qparts'];

    public function token(PartsLink24Brand $brand): PartsLink24Token
    {
        $cacheKey = 'partslink24.token.'.$brand->service;

        $cached = Cache::get($cacheKey);

        if ($cached instanceof PartsLink24Token && $cached->isValid()) {
            return $cached;
        }

        $token = $this->authorize($brand);

        Cache::put($cacheKey, $token, $token->expiresAt);

        return $token;
    }

    /**
     * @return list<array{oe: string, name: string}>
     */
    public function searchByVin(PartsLink24Brand $brand, string $vin, string $query): array
    {
        $token = $this->token($brand);
        $base = config()->string('suppliers.partslink24.base_url');

        $response = Http::asJson()
            ->timeout(config()->integer('suppliers.partslink24.timeout'))
            ->withToken($token->accessToken)
            ->get(sprintf('%s/%s/extern/search/vin', $base, $brand->group), [
                'lang' => config()->string('suppliers.partslink24.lang'),
                'serviceName' => $brand->service,
                'vin' => $vin,
                'q' => $query,
            ])
            ->throw();

        /** @var list<array<string, mixed>> $records */
        $records = data_get($response->json(), 'data.records', []);

        $rows = [];

        foreach ($records as $record) {
            $oe = data_get($record, 'recordContext.bidata_part_no');
            $name = data_get($record, 'values.name');

            if (is_string($oe) && $oe !== '' && is_string($name)) {
                $rows[] = ['oe' => $oe, 'name' => $name];
            }
        }

        return $rows;
    }

    private function authorize(PartsLink24Brand $brand): PartsLink24Token
    {
        $base = config()->string('suppliers.partslink24.base_url');
        $jar = new CookieJar();

        $this->login($base, $jar);

        $response = Http::asJson()
            ->timeout(config()->integer('suppliers.partslink24.timeout'))
            ->withOptions(['cookies' => $jar])
            ->post($base.'/auth/ext/api/1.1/authorize', [
                'serviceNames' => $this->serviceNames($brand),
                'serviceCategoryNames' => ['pl24-shop-universal', 'pl24-shop-tools'],
                'withLogin' => true,
            ])
            ->throw();

        $accessToken = $response->json('access_token');
        $expiresIn = $response->json('expires_in');

        throw_unless(
            is_string($accessToken) && $accessToken !== '' && is_int($expiresIn),
            RuntimeException::class,
            'Incomplete PartsLink24 authorize response (access_token/expires_in missing).',
        );

        $buffer = config()->integer('suppliers.partslink24.token_ttl_buffer');

        return new PartsLink24Token(
            accessToken: $accessToken,
            expiresAt: Date::now()->addSeconds(max(1, $expiresIn - $buffer)),
        );
    }

    private function login(string $base, CookieJar $jar): void
    {
        Http::asJson()
            ->timeout(config()->integer('suppliers.partslink24.timeout'))
            ->withOptions(['cookies' => $jar])
            ->post($base.'/pl24-appgtw/ext/api/1.0/login', [
                'authentication' => [
                    'account' => config()->string('suppliers.partslink24.account'),
                    'user' => config()->string('suppliers.partslink24.username'),
                    'pwd' => config()->string('suppliers.partslink24.password'),
                ],
                'device' => ['id' => '0', 'os' => 'server', 'offset' => '0', 'lang' => 'en-US', 'os-version' => '0'],
                'app-version' => '',
                'squeezeOut' => true,
            ])
            ->throw();
    }

    /**
     * @return list<string>
     */
    private function serviceNames(PartsLink24Brand $brand): array
    {
        $short = Str::replaceLast('_parts', '', $brand->service);

        return [
            ...self::BASE_SERVICES,
            $brand->service,
            'dealer-listing-pl24-'.$short,
            'pl24-parts-list-scan-'.$short,
        ];
    }
}
