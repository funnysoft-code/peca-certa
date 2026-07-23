<?php

declare(strict_types=1);

namespace App\Services\PartsLink24;

use Closure;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

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
     * Free-text VIN-scoped part search (English query).
     *
     * @return list<array{
     *     oe: string,
     *     name: string,
     *     partno: string,
     *     maingroup: string|null,
     *     subgroup: string|null,
     *     btnr: string|null
     * }>
     */
    public function searchByVin(PartsLink24Brand $brand, string $vin, string $query): array
    {
        $response = $this->authenticatedGet($brand, sprintf('/%s/extern/search/vin', $brand->group), [
            'lang' => config()->string('suppliers.partslink24.lang'),
            'serviceName' => $brand->service,
            'vin' => $vin,
            'q' => $query,
        ]);

        /** @var list<array<string, mixed>> $records */
        $records = data_get($response->json(), 'data.records', []);

        $rows = [];

        foreach ($records as $record) {
            $oe = data_get($record, 'recordContext.bidata_part_no');
            $name = data_get($record, 'values.name');
            $partno = data_get($record, 'values.partno');
            $maingroup = data_get($record, 'values.hg');
            $subgroup = data_get($record, 'values.fg');
            $btnr = data_get($record, 'values.btnr');
            if (! is_string($oe)) {
                continue;
            }

            if ($oe === '') {
                continue;
            }

            if (! is_string($name)) {
                continue;
            }

            $rows[] = [
                'oe' => $oe,
                'name' => $name,
                'partno' => is_string($partno) ? $partno : $oe,
                'maingroup' => is_string($maingroup) ? $maingroup : null,
                'subgroup' => is_string($subgroup) ? $subgroup : null,
                'btnr' => is_string($btnr) ? $btnr : null,
            ];
        }

        return $rows;
    }

    /**
     * Decode a VIN via directAccess (vehicle identification).
     *
     * @return array{
     *     vin: string,
     *     description: string,
     *     resultStatus: string,
     *     fields: list<array{description: string, value: string}>
     * }|null
     */
    public function decodeVin(PartsLink24Brand $brand, string $vin): ?array
    {
        return $this->rememberCatalog(
            'suppliers.partslink24.cache.decode_ttl',
            'partslink24.decode.'.$brand->service.'.'.mb_strtoupper($vin),
            fn (): ?array => $this->fetchDecodeVin($brand, $vin),
        );
    }

    /**
     * Top-level catalog groups for a VIN (e.g. Engine, Body).
     *
     * @return list<array{id: string, description: string}>
     */
    public function listMainGroups(PartsLink24Brand $brand, string $vin): array
    {
        /** @var list<array{id: string, description: string}> $groups */
        $groups = $this->rememberCatalog(
            'suppliers.partslink24.cache.main_groups_ttl',
            'partslink24.main.'.$brand->service.'.'.mb_strtoupper($vin),
            fn (): array => $this->fetchMainGroups($brand, $vin),
        );

        return $groups;
    }

    /**
     * Sub-groups / illustration pages under a main group (hg).
     *
     * @return list<array{id: string, description: string, kind: 'section'|'bom', btnr: string|null}>
     */
    public function listSubGroups(PartsLink24Brand $brand, string $vin, string $mainGroupId): array
    {
        /** @var list<array{id: string, description: string, kind: 'section'|'bom', btnr: string|null}> $rows */
        $rows = $this->rememberCatalog(
            'suppliers.partslink24.cache.sub_groups_ttl',
            'partslink24.sub.'.$brand->service.'.'.mb_strtoupper($vin).'.'.$mainGroupId,
            fn (): array => $this->fetchSubGroups($brand, $vin, $mainGroupId),
        );

        return $rows;
    }

    /**
     * Parts on a BOM / illustration page for a VIN.
     *
     * PL24 greys option/package rows with an `unavailable` key. Those still
     * appear on the page but are **not** factory-fit for this VIN. Non-greyed
     * rows (no `unavailable` key) are factory-fit and must be preferred.
     *
     * @return list<array{
     *     oe: string,
     *     partno: string,
     *     description: string,
     *     pos: string,
     *     qty: string,
     *     partinfoPartno: string|null,
     *     factoryFit: bool,
     *     unavailable: bool,
     *     remark: string|null,
     *     applicability: string|null,
     *     maingroup: string,
     *     btnr: string
     * }>
     */
    public function listBomParts(
        PartsLink24Brand $brand,
        string $vin,
        string $mainGroupId,
        string $btnr,
    ): array {
        return $this->listBomPage($brand, $vin, $mainGroupId, $btnr)['parts'];
    }

    /**
     * Full BOM page: parts + illustration descriptors.
     *
     * `data.images` lists illustration assets (typically `{id: "_DFLT_", name: btnr}`).
     * Bytes may be embedded (`data`/`content`/`base64`) or require a follow-up download.
     *
     * @return array{
     *     parts: list<array{
     *         oe: string,
     *         partno: string,
     *         description: string,
     *         pos: string,
     *         qty: string,
     *         partinfoPartno: string|null,
     *         factoryFit: bool,
     *         unavailable: bool,
     *         remark: string|null,
     *         applicability: string|null,
     *         maingroup: string,
     *         btnr: string
     *     }>,
     *     images: list<array<string, mixed>>,
     *     illustrationAvailable: bool
     * }
     */
    public function listBomPage(
        PartsLink24Brand $brand,
        string $vin,
        string $mainGroupId,
        string $btnr,
    ): array {
        /** @var array{parts: list<array{oe: string, partno: string, description: string, pos: string, qty: string, partinfoPartno: string|null, factoryFit: bool, unavailable: bool, remark: string|null, applicability: string|null, maingroup: string, btnr: string}>, images: list<array<string, mixed>>, illustrationAvailable: bool} $page */
        $page = $this->rememberCatalog(
            'suppliers.partslink24.cache.bom_ttl',
            'partslink24.bom.'.$brand->service.'.'.mb_strtoupper($vin).'.'.$mainGroupId.'.'.$btnr,
            fn (): array => $this->fetchBomPage($brand, $vin, $mainGroupId, $btnr),
        );

        return $page;
    }

    /**
     * Download BOM illustration bytes for a page.
     *
     * @return string|null Binary image contents, or null when PL24 has no illustration for this page.
     *
     * @throws RuntimeException When PL24 advertises an illustration but bytes cannot be obtained after retries.
     */
    public function getBomIllustrationBytes(
        PartsLink24Brand $brand,
        string $vin,
        string $mainGroupId,
        string $btnr,
    ): ?string {
        $page = $this->listBomPage($brand, $vin, $mainGroupId, $btnr);

        if (! $page['illustrationAvailable'] || $page['images'] === []) {
            return null;
        }

        $attempts = max(1, config()->integer('suppliers.partslink24.illustration_retries'));
        $lastError = 'unknown';

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $bytes = $this->resolveIllustrationBytes($brand, $vin, $mainGroupId, $btnr, $page['images']);

                if (is_string($bytes) && $bytes !== '') {
                    return $bytes;
                }

                $lastError = 'empty_body';
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        throw new RuntimeException(
            'PartsLink24 BOM illustration present but download failed after '
            .$attempts.' attempt(s) for btnr='.$btnr.' ('.$lastError.').',
        );
    }

    /**
     * Part detail page for a BOM position.
     *
     * @return array{oe: string, partno: string, description: string, fields: list<array{description: string, value: string}>}|null
     */
    public function getPartInfo(
        PartsLink24Brand $brand,
        string $vin,
        string $mainGroupId,
        string $btnr,
        string $partinfoPartno,
        string $pos,
    ): ?array {
        $response = $this->authenticatedGet($brand, sprintf('/%s/extern/partinfo/vin', $brand->group), [
            'lang' => config()->string('suppliers.partslink24.lang'),
            'serviceName' => $brand->service,
            'vin' => $vin,
            'hg' => $mainGroupId,
            'btnr' => $btnr,
            'partno' => $partinfoPartno,
            'pos' => $pos,
        ]);

        $json = $response->json();

        if (! is_array($json) || data_get($json, 'messages') !== null) {
            return null;
        }

        $fields = [];
        $oe = '';
        $partno = '';
        $description = '';

        /** @var array<string, mixed> $segments */
        $segments = data_get($json, 'data.segments', []);

        foreach ($segments as $segment) {
            if (! is_array($segment)) {
                continue;
            }

            /** @var list<array<string, mixed>> $records */
            $records = data_get($segment, 'records', []);

            foreach ($records as $record) {
                $label = data_get($record, 'values.description', data_get($record, 'values.name'));
                $value = data_get($record, 'values.value', data_get($record, 'values.partno'));
                if (! is_string($label)) {
                    continue;
                }

                if ($label === '') {
                    continue;
                }

                if (! is_string($value)) {
                    continue;
                }

                if ($value === '') {
                    continue;
                }

                $fields[] = [
                    'description' => $label,
                    'value' => str_replace('\\-', '-', $value),
                ];
            }
        }

        /** @var list<array<string, mixed>> $topRecords */
        $topRecords = data_get($json, 'data.records', []);

        foreach ($topRecords as $record) {
            $candidateOe = data_get($record, 'partno', data_get($record, 'values.partno'));
            $candidateName = data_get($record, 'description', data_get($record, 'values.description'));

            if (is_string($candidateOe) && $candidateOe !== '' && $oe === '') {
                $oe = preg_replace('/\s+/', '', $candidateOe) ?? $candidateOe;
                $partno = $candidateOe;
            }

            if (is_string($candidateName) && $candidateName !== '' && $description === '') {
                $description = str_replace('\\-', '-', $candidateName);
            }
        }

        if ($oe === '' && $fields === []) {
            return null;
        }

        return [
            'oe' => $oe !== '' ? $oe : $partinfoPartno,
            'partno' => $partno !== '' ? $partno : $partinfoPartno,
            'description' => $description,
            'fields' => $fields,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $images
     */
    private function resolveIllustrationBytes(
        PartsLink24Brand $brand,
        string $vin,
        string $mainGroupId,
        string $btnr,
        array $images,
    ): ?string {
        foreach ($images as $image) {
            $embedded = $this->embeddedImageBytes($image);

            if (is_string($embedded) && $embedded !== '') {
                return $embedded;
            }

            foreach (['url', 'src', 'href', 'path'] as $key) {
                $ref = $image[$key] ?? null;
                if (! is_string($ref)) {
                    continue;
                }

                if ($ref === '') {
                    continue;
                }

                $downloaded = $this->downloadIllustrationRef($brand, $ref);

                if (is_string($downloaded) && $downloaded !== '') {
                    return $downloaded;
                }
            }

            $imgId = $image['id'] ?? null;
            if (! is_string($imgId)) {
                continue;
            }

            if ($imgId === '') {
                continue;
            }

            $path = sprintf('/%s/extern/images/vin', $brand->group);
            $response = $this->authenticatedGet($brand, $path, [
                'lang' => config()->string('suppliers.partslink24.lang'),
                'serviceName' => $brand->service,
                'vin' => $vin,
                'hg' => $mainGroupId,
                'btnr' => $btnr,
                'imgId' => $imgId,
            ]);

            $body = $response->body();
            $contentType = (string) $response->header('Content-Type');

            if ($body !== '' && (str_contains($contentType, 'image/') || $this->looksLikeImageBinary($body))) {
                return $body;
            }

            $json = $response->json();

            if (is_array($json)) {
                /** @var array<string, mixed> $payload */
                $payload = $json;
                $embedded = $this->embeddedImageBytes($payload);

                if (is_string($embedded) && $embedded !== '') {
                    return $embedded;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function embeddedImageBytes(array $payload): ?string
    {
        foreach (['data', 'content', 'base64', 'image', 'bytes'] as $key) {
            $value = $payload[$key] ?? null;
            if (! is_string($value)) {
                continue;
            }

            if ($value === '') {
                continue;
            }

            if (str_starts_with($value, 'data:image') && str_contains($value, ',')) {
                $value = mb_substr($value, (int) mb_strpos($value, ',') + 1);
            }

            $decoded = base64_decode($value, true);

            if (is_string($decoded) && $decoded !== '' && $this->looksLikeImageBinary($decoded)) {
                return $decoded;
            }

            if ($this->looksLikeImageBinary($value)) {
                return $value;
            }
        }

        return null;
    }

    private function downloadIllustrationRef(PartsLink24Brand $brand, string $ref): ?string
    {
        $base = config()->string('suppliers.partslink24.base_url');
        $url = str_starts_with($ref, 'http') ? $ref : $base.$ref;
        $token = $this->token($brand);

        $response = $this->catalogGet($url, $token);

        if ($response->status() === 401) {
            Cache::forget('partslink24.token.'.$brand->service);
            $token = $this->token($brand);
            $response = $this->catalogGet($url, $token);
        }

        if ($response->failed()) {
            return null;
        }

        $body = $response->body();

        return $body !== '' && $this->looksLikeImageBinary($body) ? $body : null;
    }

    /**
     * @return array{
     *     vin: string,
     *     description: string,
     *     resultStatus: string,
     *     fields: list<array{description: string, value: string}>
     * }|null
     */
    private function fetchDecodeVin(PartsLink24Brand $brand, string $vin): ?array
    {
        $response = $this->authenticatedGet($brand, sprintf('/%s/extern/directAccess', $brand->group), [
            'lang' => config()->string('suppliers.partslink24.lang'),
            'serviceName' => $brand->service,
            'q' => $vin,
        ]);

        $status = data_get($response->json(), 'data.resultStatus');
        $resolvedVin = data_get($response->json(), 'data.vin');

        if (! is_string($status) || $status === '' || ! is_string($resolvedVin) || $resolvedVin === '') {
            return null;
        }

        $fields = [];

        /** @var list<array<string, mixed>> $records */
        $records = data_get($response->json(), 'data.segments.vinfoBasic.records', []);

        foreach ($records as $record) {
            $description = data_get($record, 'values.description');
            $value = data_get($record, 'values.value');

            if (is_string($description) && is_string($value) && $description !== '' && $value !== '') {
                $fields[] = [
                    'description' => $description,
                    'value' => str_replace('\\-', '-', $value),
                ];
            }
        }

        $description = data_get($response->json(), 'data.description');

        return [
            'vin' => $resolvedVin,
            'description' => is_string($description) ? str_replace('\\-', '-', $description) : '',
            'resultStatus' => $status,
            'fields' => $fields,
        ];
    }

    /**
     * @return list<array{id: string, description: string}>
     */
    private function fetchMainGroups(PartsLink24Brand $brand, string $vin): array
    {
        $response = $this->authenticatedGet($brand, sprintf('/%s/extern/groups/main-vin', $brand->group), [
            'lang' => config()->string('suppliers.partslink24.lang'),
            'serviceName' => $brand->service,
            'vin' => $vin,
        ]);

        /** @var list<array<string, mixed>> $records */
        $records = data_get($response->json(), 'data.records', []);

        $groups = [];

        foreach ($records as $record) {
            $id = data_get($record, 'values.id', data_get($record, 'id'));
            $description = data_get($record, 'values.description');

            if (is_string($id) && $id !== '' && is_string($description) && $description !== '') {
                $groups[] = ['id' => $id, 'description' => $description];
            }
        }

        return $groups;
    }

    /**
     * @return list<array{id: string, description: string, kind: 'section'|'bom', btnr: string|null}>
     */
    private function fetchSubGroups(PartsLink24Brand $brand, string $vin, string $mainGroupId): array
    {
        $response = $this->authenticatedGet($brand, sprintf('/%s/extern/groups/func-vin', $brand->group), [
            'lang' => config()->string('suppliers.partslink24.lang'),
            'serviceName' => $brand->service,
            'vin' => $vin,
            'hg' => $mainGroupId,
        ]);

        /** @var list<array<string, mixed>> $records */
        $records = data_get($response->json(), 'data.records', []);

        $rows = [];

        foreach ($records as $record) {
            $id = data_get($record, 'id');
            $description = data_get($record, 'values.descr', data_get($record, 'values.description'));
            $characteristic = data_get($record, 'characteristic');
            if (! is_string($id)) {
                continue;
            }

            if ($id === '') {
                continue;
            }

            if (! is_string($description)) {
                continue;
            }

            if ($description === '') {
                continue;
            }

            $isSection = $characteristic === 'sectionrow';
            $btnr = null;

            if (! $isSection && str_contains($id, '_')) {
                $btnr = $id;
            }

            $rows[] = [
                'id' => $id,
                'description' => str_replace('\\-', '-', $description),
                'kind' => $isSection ? 'section' : 'bom',
                'btnr' => $btnr,
            ];
        }

        return $rows;
    }

    /**
     * @return array{
     *     parts: list<array{
     *         oe: string,
     *         partno: string,
     *         description: string,
     *         pos: string,
     *         qty: string,
     *         partinfoPartno: string|null,
     *         factoryFit: bool,
     *         unavailable: bool,
     *         remark: string|null,
     *         applicability: string|null,
     *         maingroup: string,
     *         btnr: string
     *     }>,
     *     images: list<array<string, mixed>>,
     *     illustrationAvailable: bool
     * }
     */
    private function fetchBomPage(
        PartsLink24Brand $brand,
        string $vin,
        string $mainGroupId,
        string $btnr,
    ): array {
        $response = $this->authenticatedGet($brand, sprintf('/%s/extern/bom/vin', $brand->group), [
            'lang' => config()->string('suppliers.partslink24.lang'),
            'serviceName' => $brand->service,
            'vin' => $vin,
            'hg' => $mainGroupId,
            'btnr' => $btnr,
        ]);

        $json = $response->json();

        /** @var list<array<string, mixed>> $records */
        $records = data_get($json, 'data.records', []);
        $imagesRaw = data_get($json, 'data.images', []);
        /** @var list<array<string, mixed>> $images */
        $images = is_array($imagesRaw) ? array_values(array_filter($imagesRaw, is_array(...))) : [];

        $parts = [];
        $applicability = null;

        foreach ($records as $record) {
            $description = data_get($record, 'description', data_get($record, 'values.description'));
            $oe = data_get($record, 'partno');
            $isSection = data_get($record, 'characteristic') === 'sectionrow'
                || ! is_string($oe)
                || $oe === '';

            if ($isSection) {
                if (is_string($description) && $description !== '') {
                    $applicability = $this->cleanPl24Text($description);
                }

                continue;
            }

            if (! is_string($description)) {
                continue;
            }

            if ($description === '') {
                continue;
            }

            $partno = data_get($record, 'values.partno');
            $pos = data_get($record, 'values.pos', data_get($record, 'pos'));
            $qty = data_get($record, 'values.qty');
            $linkPath = data_get($record, 'link.path');
            $remark = data_get($record, 'values.remark');
            $unavailable = array_key_exists('unavailable', $record);

            $parts[] = [
                'oe' => $oe,
                'partno' => is_string($partno) ? $partno : $oe,
                'description' => $this->cleanPl24Text($description),
                'pos' => is_string($pos) ? $pos : (is_numeric($pos) ? (string) $pos : ''),
                'qty' => is_string($qty) ? $qty : (is_numeric($qty) ? (string) $qty : ''),
                'partinfoPartno' => $this->partinfoPartnoFromPath(is_string($linkPath) ? $linkPath : null),
                'factoryFit' => ! $unavailable,
                'unavailable' => $unavailable,
                'remark' => is_string($remark) && $remark !== '' ? $this->cleanPl24Text($remark) : null,
                'applicability' => $applicability,
                'maingroup' => $mainGroupId,
                'btnr' => $btnr,
            ];
        }

        return [
            'parts' => $parts,
            'images' => $images,
            'illustrationAvailable' => $images !== [],
        ];
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function rememberCatalog(string $ttlConfigKey, string $cacheKey, Closure $callback): mixed
    {
        $ttl = config()->integer($ttlConfigKey);

        if ($ttl <= 0) {
            return $callback();
        }

        return Cache::remember($cacheKey, $ttl, $callback);
    }

    private function looksLikeImageBinary(string $bytes): bool
    {
        if (str_starts_with($bytes, "\x89PNG") || str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return true;
        }

        if (str_starts_with($bytes, 'GIF8') || str_starts_with($bytes, 'RIFF')) {
            return true;
        }

        return str_starts_with(mb_ltrim($bytes), '<svg') || str_starts_with(mb_ltrim($bytes), '<?xml');
    }

    private function cleanPl24Text(string $value): string
    {
        return Str::squish(str_replace(['\\-', "\r\n", "\r", "\n"], ['-', ' ', ' ', ' '], $value));
    }

    /**
     * @param  array<string, scalar|null>  $query
     */
    private function authenticatedGet(PartsLink24Brand $brand, string $path, array $query): Response
    {
        $token = $this->token($brand);
        $base = config()->string('suppliers.partslink24.base_url');
        $response = $this->catalogGet($base.$path, $token, $query);

        if ($response->status() === 401) {
            Cache::forget('partslink24.token.'.$brand->service);
            $token = $this->token($brand);
            $response = $this->catalogGet($base.$path, $token, $query);
        }

        $response->throw();

        return $response;
    }

    /**
     * @param  array<string, scalar|null>  $query
     */
    private function catalogGet(string $url, PartsLink24Token $token, array $query = []): Response
    {
        $this->paceCatalogRequest();

        $jar = (bool) config('suppliers.partslink24.session.send_cookies', true)
            ? $token->cookieJar()
            : null;

        $request = $this->pendingRequest($jar, 'xhr')
            ->withToken($token->accessToken);

        return $query === []
            ? $request->get($url)
            : $request->get($url, $query);
    }

    private function authorize(PartsLink24Brand $brand): PartsLink24Token
    {
        $this->assertProxyReady();
        $this->assertWithinBusinessHours();

        $base = config()->string('suppliers.partslink24.base_url');
        $jar = new CookieJar;

        $this->warmUpSession($base, $jar);
        $this->login($base, $jar);
        $this->sleepRandomMs(
            config()->integer('suppliers.partslink24.session.auth_think_ms_min'),
            config()->integer('suppliers.partslink24.session.auth_think_ms_max'),
        );

        $this->paceMinGap();
        $response = $this->pendingRequest($jar, 'xhr')
            ->post($base.'/auth/ext/api/1.1/authorize', [
                'serviceNames' => $this->serviceNames($brand),
                'serviceCategoryNames' => ['pl24-shop-universal', 'pl24-shop-tools'],
                'withLogin' => true,
            ])
            ->throw();

        $this->markRequestSent();

        $accessToken = $response->json('access_token');
        $expiresIn = $response->json('expires_in');

        throw_unless(
            is_string($accessToken) && $accessToken !== '' && is_int($expiresIn),
            RuntimeException::class,
            'Incomplete PartsLink24 authorize response (access_token/expires_in missing).',
        );

        $buffer = config()->integer('suppliers.partslink24.token_ttl_buffer');

        /** @var list<array<string, mixed>> $cookies */
        $cookies = array_values($jar->toArray());

        return new PartsLink24Token(
            accessToken: $accessToken,
            expiresAt: Date::now()->addSeconds(max(1, $expiresIn - $buffer)),
            cookies: $cookies,
        );
    }

    private function warmUpSession(string $base, CookieJar $jar): void
    {
        if (! (bool) config('suppliers.partslink24.session.warm_up', true)) {
            return;
        }

        $this->paceMinGap();
        // Live capture shell: https://www.partslink24.com/portal-ui
        $shell = config('suppliers.partslink24.referer_path', '/portal-ui');
        $shell = is_string($shell) && $shell !== '' ? $shell : '/portal-ui';
        if (! str_starts_with($shell, '/')) {
            $shell = '/'.$shell;
        }

        $this->pendingRequest($jar, 'document')
            ->get(mb_rtrim($base, '/').$shell);
        $this->markRequestSent();

        $this->sleepRandomMs(
            config()->integer('suppliers.partslink24.session.think_ms_min'),
            config()->integer('suppliers.partslink24.session.think_ms_max'),
        );
    }

    /**
     * Choose login API:
     * - admin (legacy appgtw): nested authentication + device; squeezeOut often allowed
     * - other users (portal auth/1.1): flat account/user/password; Chrome path (ricardo squeeze works here)
     */
    private function usesPortalLogin(): bool
    {
        $strategy = config('suppliers.partslink24.login_strategy', 'auto');

        if ($strategy === 'portal') {
            return true;
        }

        if ($strategy === 'appgtw') {
            return false;
        }

        $username = config('suppliers.partslink24.username');
        $normalized = is_string($username) ? mb_strtolower($username) : '';

        return $normalized !== 'admin';
    }

    private function login(string $base, CookieJar $jar): void
    {
        if ($this->usesPortalLogin()) {
            $this->loginViaPortal($base, $jar);

            return;
        }

        $this->loginViaAppgtw($base, $jar);
    }

    /**
     * Chrome SPA path: POST /auth/ext/api/1.1/login
     * Body: { account, user, password, squeezeOut }
     * Success: loginStatus=OK + sessionToken (and PL24TOKEN cookies).
     * Seat taken without squeeze: HTTP 400 urn:login:session-limit-exceeded.
     */
    private function loginViaPortal(string $base, CookieJar $jar): void
    {
        $preferSqueeze = (bool) config('suppliers.partslink24.squeeze_out', true);
        $attempts = $preferSqueeze ? [true, false] : [false, true];
        $lastStatus = 'unknown';

        foreach ($attempts as $squeezeOut) {
            $attemptJar = new CookieJar;
            $this->copySessionCookies($jar, $attemptJar);

            $response = $this->postPortalLogin($base, $attemptJar, $squeezeOut);
            $loginStatus = $response->json('loginStatus');
            $type = $response->json('type');
            $lastStatus = is_string($loginStatus) && $loginStatus !== ''
                ? $loginStatus
                : (is_string($type) && $type !== '' ? $type : (string) $response->status());

            // Seat taken without squeeze, or other recoverable soft failures → try opposite squeeze.
            if ($response->status() === 400 && is_string($type) && str_contains($type, 'session-limit')) {
                continue;
            }

            if ($response->status() === 403) {
                continue;
            }

            if ($response->failed()) {
                $response->throw();
            }

            if ($this->portalLoginEstablishedSession($response, $attemptJar)) {
                $this->copySessionCookies($attemptJar, $jar);

                return;
            }
        }

        throw new RuntimeException(
            'PartsLink24 portal login did not establish a session (status='.$lastStatus.'). '
            .'Another session may be active. Log out other sessions for this PL24 user, '
            .'or enable squeeze_out (portal path supports squeezeOut for non-admin users).',
        );
    }

    /**
     * Legacy admin path: POST /pl24-appgtw/ext/api/1.0/login
     * Nested authentication + device. Non-admin squeezeOut often HTTP 403.
     */
    private function loginViaAppgtw(string $base, CookieJar $jar): void
    {
        $preferSqueeze = (bool) config('suppliers.partslink24.squeeze_out', true);

        // Order: configured preference first, then the opposite. Live accounts vary:
        // - squeezeOut=true may 403 OR succeed with a PL24TOKEN cookie (JSON token often null)
        // - squeezeOut=false may return USER_ALREADY_LOGGED_IN with no cookie when the seat is taken
        $attempts = $preferSqueeze ? [true, false] : [false, true];

        $lastStatus = 'unknown';

        foreach ($attempts as $squeezeOut) {
            // Keep warm-up cookies; clone into a fresh attempt jar so a failed
            // USER_ALREADY_LOGGED_IN response does not leave a half-empty cookie state.
            $attemptJar = new CookieJar;
            $this->copySessionCookies($jar, $attemptJar);

            $response = $this->postAppgtwLogin($base, $attemptJar, $squeezeOut);
            $statusField = $response->json('status');
            $lastStatus = is_string($statusField) ? $statusField : (string) $response->status();

            if ($response->status() === 403) {
                continue;
            }

            if ($response->failed()) {
                $response->throw();
            }

            if ($this->appgtwLoginEstablishedSession($response, $attemptJar)) {
                $this->copySessionCookies($attemptJar, $jar);

                return;
            }
        }

        throw new RuntimeException(
            'PartsLink24 appgtw login did not establish a session (status='.$lastStatus.'). '
            .'Another session may be active, or squeezeOut is rejected for this account. '
            .'Log out browser/other app sessions for this PL24 user, use a dedicated app account, '
            .'or switch login_strategy to portal for non-admin users.',
        );
    }

    private function portalLoginEstablishedSession(Response $response, CookieJar $jar): bool
    {
        $loginStatus = $response->json('loginStatus');
        $sessionToken = $response->json('sessionToken');

        if (is_string($loginStatus) && mb_strtoupper($loginStatus) === 'OK'
            && is_string($sessionToken) && $sessionToken !== '') {
            return true;
        }

        return $this->hasPl24SessionCookie($jar);
    }

    private function appgtwLoginEstablishedSession(Response $response, CookieJar $jar): bool
    {
        $token = $response->json('token');

        if (is_string($token) && $token !== '') {
            return true;
        }

        // Successful squeeze-out often sets PL24TOKEN with a null JSON "token" field.
        return $this->hasPl24SessionCookie($jar);
    }

    private function hasPl24SessionCookie(CookieJar $jar): bool
    {
        foreach ($jar->toArray() as $cookie) {
            /** @var array<string, mixed> $cookie */
            $name = $cookie['Name'] ?? null;

            if (is_string($name) && $name !== '' && str_contains(mb_strtoupper($name), 'PL24')) {
                return true;
            }
        }

        return false;
    }

    private function copySessionCookies(CookieJar $from, CookieJar $to): void
    {
        foreach ($from->toArray() as $cookie) {
            /** @var array<string, mixed> $cookie */
            $to->setCookie(new SetCookie($cookie));
        }
    }

    private function postPortalLogin(string $base, CookieJar $jar, bool $squeezeOut): Response
    {
        $this->paceMinGap();

        // Live Chrome SPA body (ricardo capture). Field is "password", not "pwd".
        $response = $this->pendingRequest($jar, 'xhr')
            ->post($base.'/auth/ext/api/1.1/login', [
                'account' => config()->string('suppliers.partslink24.account'),
                'user' => config()->string('suppliers.partslink24.username'),
                'password' => config()->string('suppliers.partslink24.password'),
                'squeezeOut' => $squeezeOut,
            ]);

        $this->markRequestSent();

        return $response;
    }

    private function postAppgtwLogin(string $base, CookieJar $jar, bool $squeezeOut): Response
    {
        $this->paceMinGap();

        /** @var array<string, mixed> $payload */
        $payload = [
            'authentication' => [
                'account' => config()->string('suppliers.partslink24.account'),
                'user' => config()->string('suppliers.partslink24.username'),
                'pwd' => config()->string('suppliers.partslink24.password'),
            ],
            'device' => $this->devicePayload(),
            'app-version' => config()->string('suppliers.partslink24.app_version'),
            'squeezeOut' => $squeezeOut,
        ];

        $extra = config('suppliers.partslink24.login_extra', []);

        if (is_array($extra) && $extra !== []) {
            /** @var array<string, mixed> $extra */
            foreach ($extra as $key => $value) {
                if (in_array($key, ['authentication', 'device', 'app-version', 'squeezeOut'], true)) {
                    continue;
                }

                $payload[$key] = $value;
            }
        }

        $response = $this->pendingRequest($jar, 'xhr')
            ->post($base.'/pl24-appgtw/ext/api/1.0/login', $payload);

        $this->markRequestSent();

        return $response;
    }

    /**
     * Shared browser-like client for every PL24 call (warm-up, login, authorize, catalog, images).
     *
     * @param  'xhr'|'document'  $mode
     */
    private function pendingRequest(?CookieJar $jar = null, string $mode = 'xhr'): PendingRequest
    {
        $request = Http::timeout(config()->integer('suppliers.partslink24.timeout'))
            ->withUserAgent(config()->string('suppliers.partslink24.user_agent'))
            ->withHeaders($this->browserHeaders($mode));

        if ($mode === 'xhr') {
            $request = $request->asJson();
        }

        $options = $this->transportOptions();

        if ($jar instanceof CookieJar) {
            $options['cookies'] = $jar;
        }

        if ($options !== []) {
            return $request->withOptions($options);
        }

        return $request;
    }

    /**
     * Fail closed when require_proxy is on: missing or dead shop proxy means no PL24 traffic.
     * Health is cached briefly so authorize + catalog do not re-probe every request.
     */
    private function assertProxyReady(): void
    {
        if (! (bool) config('suppliers.partslink24.require_proxy', true)) {
            return;
        }

        $proxy = config('suppliers.partslink24.proxy');

        throw_if(! is_string($proxy) || $proxy === '', RuntimeException::class, 'PartsLink24 require_proxy is enabled but PARTSLINK24_PROXY is empty. '
        .'Set the shop 3proxy URL or set PARTSLINK24_REQUIRE_PROXY=false only in emergencies.');

        $cacheKey = 'partslink24.proxy_ok.'.hash('xxh128', $proxy);
        $cached = Cache::get($cacheKey);

        if ($cached === true) {
            return;
        }

        try {
            $ip = mb_trim(Http::withOptions(['proxy' => $proxy])
                ->timeout(15)
                ->get('https://api.ipify.org')
                ->throw()
                ->body());
        } catch (Throwable $throwable) {
            throw new RuntimeException('PartsLink24 shop proxy is down or unreachable; refusing PL24 access. '
            .'Run bin/partslink24-proxy-test.sh. ('.$throwable->getMessage().')', $throwable->getCode(), previous: $throwable);
        }

        throw_if($ip === '' || ! preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $ip), RuntimeException::class, 'PartsLink24 shop proxy did not return a public IPv4; refusing PL24 access. '
        .'Run bin/partslink24-proxy-test.sh.');

        Cache::put($cacheKey, true, Date::now()->addSeconds(60));
    }

    /**
     * @return array<string, mixed>
     */
    private function transportOptions(): array
    {
        $options = [];

        $proxy = config('suppliers.partslink24.proxy');

        if (is_string($proxy) && $proxy !== '') {
            $options['proxy'] = $proxy;
        }

        // HTTP/2 over TLS when libcurl allows it. Improves protocol parity with Chrome;
        // does not spoof full JA3 (that needs a browser or curl-impersonate edge).
        if ((bool) config('suppliers.partslink24.http2', true) && defined('CURLOPT_HTTP_VERSION') && defined('CURL_HTTP_VERSION_2TLS')) {
            $options['curl'] = [
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2TLS,
            ];
        }

        return $options;
    }

    /**
     * @param  'xhr'|'document'  $mode
     * @return array<string, string>
     */
    private function browserHeaders(string $mode = 'xhr'): array
    {
        $base = mb_rtrim(config()->string('suppliers.partslink24.base_url'), '/');
        $refererPath = config('suppliers.partslink24.referer_path', '/portal-ui');
        $refererPath = is_string($refererPath) && $refererPath !== '' ? $refererPath : '/portal-ui';
        if (! str_starts_with($refererPath, '/')) {
            $refererPath = '/'.$refererPath;
        }

        $headers = [
            'Accept-Language' => config()->string('suppliers.partslink24.accept_language'),
            'Accept-Encoding' => 'gzip, deflate, br, zstd',
            'Origin' => $base,
            // Live SPA XHR uses the portal shell as Referer (not bare "/").
            'Referer' => $base.$refererPath,
            'sec-ch-ua' => config()->string('suppliers.partslink24.sec_ch_ua'),
            'sec-ch-ua-mobile' => config()->string('suppliers.partslink24.sec_ch_ua_mobile'),
            'sec-ch-ua-platform' => config()->string('suppliers.partslink24.sec_ch_ua_platform'),
            'sec-fetch-site' => 'same-origin',
            'sec-fetch-user' => '?1',
            'Upgrade-Insecure-Requests' => '1',
        ];

        if ($mode === 'document') {
            $headers['Accept'] = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8';
            $headers['sec-fetch-mode'] = 'navigate';
            $headers['sec-fetch-dest'] = 'document';
            // Document navigations still use the site root as the natural entry Referer.
            $headers['Referer'] = $base.'/';
        } else {
            $headers['Accept'] = 'application/json, text/plain, */*';
            $headers['sec-fetch-mode'] = 'cors';
            $headers['sec-fetch-dest'] = 'empty';
            unset($headers['sec-fetch-user'], $headers['Upgrade-Insecure-Requests']);
        }

        $extra = config('suppliers.partslink24.extra_headers', []);

        if (is_array($extra)) {
            foreach ($extra as $name => $value) {
                if (is_string($name) && $name !== '' && is_scalar($value)) {
                    $headers[$name] = (string) $value;
                }
            }
        }

        return $headers;
    }

    /**
     * @return array{id: string, os: string, offset: string, lang: string, os-version: string}
     */
    private function devicePayload(): array
    {
        $offset = config('suppliers.partslink24.device.offset');

        if (! is_string($offset) || $offset === '') {
            $offset = (string) (int) round(Date::now()->getOffset() / 60);
        }

        return [
            'id' => config()->string('suppliers.partslink24.device.id'),
            'os' => config()->string('suppliers.partslink24.device.os'),
            'offset' => $offset,
            'lang' => config()->string('suppliers.partslink24.device.lang'),
            'os-version' => config()->string('suppliers.partslink24.device.os_version'),
        ];
    }

    private function paceCatalogRequest(): void
    {
        $this->assertVolumeBudget();
        $this->applyRateLimit();
        $this->paceMinGap();
        $this->applyJitter();
        $this->markRequestSent();
        $this->hitVolumeCounters();
    }

    private function paceMinGap(): void
    {
        $minGap = config()->integer('suppliers.partslink24.session.min_gap_ms');

        if ($minGap <= 0) {
            return;
        }

        $key = 'partslink24.last_request_ms.'.$this->accountKey();
        $last = Cache::get($key);

        if (! is_int($last) && ! is_float($last)) {
            return;
        }

        $elapsed = (int) floor((microtime(true) * 1000) - (float) $last);
        $remaining = $minGap - $elapsed;

        if ($remaining > 0) {
            Sleep::for($remaining)->milliseconds();
        }
    }

    private function markRequestSent(): void
    {
        Cache::put(
            'partslink24.last_request_ms.'.$this->accountKey(),
            (int) floor(microtime(true) * 1000),
            Date::now()->addHour(),
        );
    }

    private function applyRateLimit(): void
    {
        $max = config()->integer('suppliers.partslink24.rate_limit_per_minute');

        if ($max <= 0) {
            return;
        }

        $key = 'partslink24.rate.'.$this->accountKey();

        while (RateLimiter::tooManyAttempts($key, $max)) {
            $wait = max(1, RateLimiter::availableIn($key));
            Sleep::for($wait)->seconds();

            // Pest freezes the clock + Sleep::fake does not advance decay timers.
            // Clear after one wait so unit tests cannot spin forever.
            if (app()->runningUnitTests()) {
                RateLimiter::clear($key);
            }
        }

        RateLimiter::hit($key, 60);
    }

    private function applyJitter(): void
    {
        $this->sleepRandomMs(
            config()->integer('suppliers.partslink24.jitter_ms_min'),
            config()->integer('suppliers.partslink24.jitter_ms_max'),
        );
    }

    private function sleepRandomMs(int $min, int $max): void
    {
        if ($max <= 0 || $max < $min) {
            return;
        }

        // Skew toward slightly longer pauses (less metronomic than uniform).
        $low = max(0, $min);
        $roll = random_int($low, $max);
        $skew = random_int($low, $max);
        $ms = (int) floor(($roll + $skew) / 2);

        if ($ms > 0) {
            Sleep::for($ms)->milliseconds();
        }
    }

    private function assertVolumeBudget(): void
    {
        $perHour = config()->integer('suppliers.partslink24.volume.max_per_hour');
        $perDay = config()->integer('suppliers.partslink24.volume.max_per_day');

        throw_if($perHour > 0 && RateLimiter::tooManyAttempts('partslink24.vol.hour.'.$this->accountKey(), $perHour), RuntimeException::class, 'PartsLink24 hourly catalog budget exceeded (max_per_hour='.$perHour.'). '
        .'Reduce agent fan-out or raise PARTSLINK24_MAX_PER_HOUR.');

        throw_if($perDay > 0 && RateLimiter::tooManyAttempts('partslink24.vol.day.'.$this->accountKey(), $perDay), RuntimeException::class, 'PartsLink24 daily catalog budget exceeded (max_per_day='.$perDay.'). '
        .'Reduce volume or raise PARTSLINK24_MAX_PER_DAY.');
    }

    private function hitVolumeCounters(): void
    {
        $perHour = config()->integer('suppliers.partslink24.volume.max_per_hour');
        $perDay = config()->integer('suppliers.partslink24.volume.max_per_day');

        if ($perHour > 0) {
            RateLimiter::hit('partslink24.vol.hour.'.$this->accountKey(), 3600);
        }

        if ($perDay > 0) {
            RateLimiter::hit('partslink24.vol.day.'.$this->accountKey(), 86400);
        }
    }

    private function assertWithinBusinessHours(): void
    {
        if (! (bool) config('suppliers.partslink24.volume.business_hours_only', false)) {
            return;
        }

        $tz = config()->string('suppliers.partslink24.volume.business_timezone');
        $start = config()->integer('suppliers.partslink24.volume.business_hours_start');
        $end = config()->integer('suppliers.partslink24.volume.business_hours_end');
        $hour = (int) Date::now($tz)->format('G');

        throw_if($hour < $start || $hour >= $end, RuntimeException::class, 'PartsLink24 business-hours gate: refused new session at hour='.$hour
        .' (allowed '.$start.'–'.$end.' '.$tz.'). '
        .'Disable PARTSLINK24_BUSINESS_HOURS_ONLY or adjust the window.');
    }

    private function accountKey(): string
    {
        $account = config('suppliers.partslink24.account');

        return is_string($account) && $account !== '' ? $account : 'default';
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

    private function partinfoPartnoFromPath(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $query = parse_url($path, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return null;
        }

        parse_str($query, $params);
        $partno = $params['partno'] ?? null;

        return is_string($partno) && $partno !== '' ? $partno : null;
    }
}
