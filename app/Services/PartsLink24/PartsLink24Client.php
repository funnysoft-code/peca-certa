<?php

declare(strict_types=1);

namespace App\Services\PartsLink24;

use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
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
     * Top-level catalog groups for a VIN (e.g. Engine, Body).
     *
     * @return list<array{id: string, description: string}>
     */
    public function listMainGroups(PartsLink24Brand $brand, string $vin): array
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
     * Sub-groups / illustration pages under a main group (hg).
     *
     * @return list<array{id: string, description: string, kind: 'section'|'bom', btnr: string|null}>
     */
    public function listSubGroups(PartsLink24Brand $brand, string $vin, string $mainGroupId): array
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

        $response = Http::timeout(config()->integer('suppliers.partslink24.timeout'))
            ->withToken($token->accessToken)
            ->get($url);

        if ($response->status() === 401) {
            Cache::forget('partslink24.token.'.$brand->service);
            $token = $this->token($brand);
            $response = Http::timeout(config()->integer('suppliers.partslink24.timeout'))
                ->withToken($token->accessToken)
                ->get($url);
        }

        if ($response->failed()) {
            return null;
        }

        $body = $response->body();

        return $body !== '' && $this->looksLikeImageBinary($body) ? $body : null;
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
        $response = $this->get($path, $query, $token);

        if ($response->status() === 401) {
            Cache::forget('partslink24.token.'.$brand->service);
            $token = $this->token($brand);
            $response = $this->get($path, $query, $token);
        }

        $response->throw();

        return $response;
    }

    /**
     * @param  array<string, scalar|null>  $query
     */
    private function get(string $path, array $query, PartsLink24Token $token): Response
    {
        $base = config()->string('suppliers.partslink24.base_url');

        return Http::asJson()
            ->timeout(config()->integer('suppliers.partslink24.timeout'))
            ->withToken($token->accessToken)
            ->get($base.$path, $query);
    }

    private function authorize(PartsLink24Brand $brand): PartsLink24Token
    {
        $base = config()->string('suppliers.partslink24.base_url');
        $jar = new CookieJar;

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
        $preferSqueeze = (bool) config('suppliers.partslink24.squeeze_out', true);

        // Order: configured preference first, then the opposite. Live accounts vary:
        // - squeezeOut=true may 403 OR succeed with a PL24TOKEN cookie (JSON token often null)
        // - squeezeOut=false may return USER_ALREADY_LOGGED_IN with no cookie when the seat is taken
        $attempts = $preferSqueeze ? [true, false] : [false, true];

        $lastStatus = 'unknown';

        foreach ($attempts as $squeezeOut) {
            // Fresh jar per attempt so a failed USER_ALREADY_LOGGED_IN response
            // does not leave a half-empty cookie state.
            $attemptJar = new CookieJar;
            $response = $this->postLogin($base, $attemptJar, $squeezeOut);
            $statusField = $response->json('status');
            $lastStatus = is_string($statusField) ? $statusField : (string) $response->status();

            if ($response->status() === 403) {
                continue;
            }

            if ($response->failed()) {
                $response->throw();
            }

            if ($this->loginEstablishedSession($response, $attemptJar)) {
                $this->copySessionCookies($attemptJar, $jar);

                return;
            }
        }

        throw new RuntimeException(
            'PartsLink24 login did not establish a session (status='.$lastStatus.'). '
            .'Another session may be active, or squeezeOut is rejected for this account. '
            .'Log out browser/other app sessions for this PL24 user, or use a dedicated app account.',
        );
    }

    private function loginEstablishedSession(Response $response, CookieJar $jar): bool
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

    private function postLogin(string $base, CookieJar $jar, bool $squeezeOut): Response
    {
        return Http::asJson()
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
                'squeezeOut' => $squeezeOut,
            ]);
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
