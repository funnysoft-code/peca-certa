<?php

declare(strict_types=1);

return [
    'autodelta' => [
        'auth_url' => env('AUTODELTA_AUTH_URL', 'https://webservice.tecalliance.services/auth/v1/services/AuthWS.jsonEndpoint'),
        'catalog_url' => env('AUTODELTA_CATALOG_URL', 'https://webservice.tecalliance.services/webcat30/v1/services/WebCat30WS.jsonEndpoint'),
        'search_url' => env('AUTODELTA_SEARCH_URL', 'https://webservice.tecalliance.services/pegasus-3-0/services/TecdocToCatDLB.jsonEndpoint'),
        'catalog_id' => env('AUTODELTA_CATALOG_ID'),
        'catalog_key' => env('AUTODELTA_CATALOG_KEY', 'autodelta'),
        'catalog_user_id' => env('AUTODELTA_CATALOG_USER_ID'),
        'provider' => (int) env('AUTODELTA_PROVIDER', 1066),
        'username' => env('AUTODELTA_USERNAME'),
        'password' => env('AUTODELTA_PASSWORD'),
        'lang' => env('AUTODELTA_LANG', 'pt'),
        'country' => env('AUTODELTA_COUNTRY', 'PT'),
        // WebCat30 webshop base; used to deep-link a variant to its article page.
        'webshop_url' => env('AUTODELTA_WEBSHOP_URL', 'https://web.tecalliance.net/autodelta/pt'),
    ],

    'autozitania' => [
        'entry_url' => env('AUTOZITANIA_ENTRY_URL', 'https://web2.carparts-cat.com/default.aspx?11=102&14=15&1115=1&1281=17=0&10=CB42290652B84321A1D2E66B1FA73DCE102015&12=1400'),
        'username' => env('AUTOZITANIA_USERNAME'),
        'password' => env('AUTOZITANIA_PASSWORD'),
        // Local Bun + Playwright sidecar. Unused when `http_url` is set.
        'bun_binary' => env('AUTOZITANIA_BUN_BINARY', 'bun'),
        'script_timeout' => (int) env('AUTOZITANIA_SCRIPT_TIMEOUT', 120),
        // Production: Cloudflare Browser Rendering Worker (`workers/zitania-browser`).
        // When non-empty, AutoZitaniaClient POSTs { reference } instead of shelling out.
        'http_url' => env('AUTOZITANIA_HTTP_URL', ''),
        'http_token' => env('AUTOZITANIA_HTTP_TOKEN', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | PartsLink24
    |--------------------------------------------------------------------------
    |
    | Fingerprint + session + pacing are HARDCODED from a live real-Chrome CDP
    | capture (2026-07-23, portal-ui). Source of truth:
    | docs/partslink24/capture-2026-07-23-real-chrome.md
    |
    | Only secrets / deploy wiring stay in env: account, username, password,
    | optional shop proxy, diagrams disk. Do not reintroduce fingerprint via .env.
    |
    | Testing (APP_ENV=testing): warm-up/pacing/volume/cache forced off so the
    | suite stays deterministic (Http::fake counts, no real delays).
    |
    */
    'partslink24' => [
        'base_url' => 'https://www.partslink24.com',
        'account' => env('PARTSLINK24_ACCOUNT'),
        'username' => env('PARTSLINK24_USERNAME'),
        'password' => env('PARTSLINK24_PASSWORD'),
        'timeout' => 30,
        // Catalog query lang (search/decode/BOM). English index is more complete than PT.
        'lang' => 'en',
        'token_ttl_buffer' => 30,
        // Prefer not to squeeze humans; dedicated app account + free seat.
        // Portal login (non-admin) supports squeezeOut:true; appgtw often 403s for non-admin.
        'squeeze_out' => true,
        // Login API strategy:
        // - auto: username "admin" → appgtw legacy; any other user → portal auth/1.1 (Chrome path)
        // - portal: always /auth/ext/api/1.1/login (flat account/user/password)
        // - appgtw: always /pl24-appgtw/ext/api/1.0/login (nested authentication + device)
        'login_strategy' => 'auto',
        'max_candidates' => 5,
        'illustration_retries' => 3,
        'diagrams_disk' => env('PARTSLINK24_DIAGRAMS_DISK', 'pl24_diagrams'),

        // --- Real Chrome/150.0.7871.129 (Mac arm, Europe/Lisbon) ---
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36',
        // Live XHR Accept-Language on authorize/portal APIs.
        'accept_language' => 'pt',
        'app_version' => '1.0.0',
        // Exact Client Hints brand order from live authorize/catalog requests.
        'sec_ch_ua' => '"Not;A=Brand";v="8", "Chromium";v="150", "Google Chrome";v="150"',
        'sec_ch_ua_mobile' => '?0',
        'sec_ch_ua_platform' => '"macOS"',
        // SPA shell used as Referer on XHR: https://www.partslink24.com/portal-ui
        'referer_path' => '/portal-ui',
        // Extra headers layered on every request (beyond pendingRequest defaults).
        'extra_headers' => [
            // Live Chrome also sends these; client already sets most of them, listed for parity.
            'Cache-Control' => 'no-cache',
            'Pragma' => 'no-cache',
        ],
        // Extra login body keys (non-core). Empty until a login HAR fills this.
        'login_extra' => [],
        'device' => [
            // Stable app-device id (not "0", not os:server). Keep fixed across deploys.
            'id' => '019f8f10-5e1f-7c00-8a00-c0ffee000001',
            'os' => 'MacOS',
            // navigator.userAgentData high-entropy platformVersion from capture.
            'os_version' => '26.5.2',
            // navigator.language from capture (Chrome UI was en-US).
            'lang' => 'en-US',
            // Europe/Lisbon summer: getTimezoneOffset() === -60 → +60 minutes east of UTC.
            'offset' => '60',
        ],
        // Optional shop egress only (secret). Empty = host IP.
        'proxy' => env('PARTSLINK24_PROXY', ''),
        // Prefer HTTP/2 when libcurl allows (closer to Chrome; not full JA3).
        'http2' => true,

        // Session life matching a browser: HTML shell → think → login → think → authorize.
        'session' => [
            'warm_up' => env('APP_ENV') !== 'testing',
            'send_cookies' => true,
            'think_ms_min' => env('APP_ENV') === 'testing' ? 0 : 400,
            'think_ms_max' => env('APP_ENV') === 'testing' ? 0 : 1200,
            'auth_think_ms_min' => env('APP_ENV') === 'testing' ? 0 : 250,
            'auth_think_ms_max' => env('APP_ENV') === 'testing' ? 0 : 800,
            'min_gap_ms' => env('APP_ENV') === 'testing' ? 0 : 200,
        ],
        'jitter_ms_min' => env('APP_ENV') === 'testing' ? 0 : 200,
        'jitter_ms_max' => env('APP_ENV') === 'testing' ? 0 : 700,
        'rate_limit_per_minute' => env('APP_ENV') === 'testing' ? 0 : 30,
        'volume' => [
            'max_per_hour' => env('APP_ENV') === 'testing' ? 0 : 180,
            'max_per_day' => env('APP_ENV') === 'testing' ? 0 : 1200,
            'business_hours_only' => false,
            'business_hours_start' => 7,
            'business_hours_end' => 20,
            'business_timezone' => 'Europe/Lisbon',
        ],
        'cache' => [
            'decode_ttl' => env('APP_ENV') === 'testing' ? 0 : 1800,
            'main_groups_ttl' => env('APP_ENV') === 'testing' ? 0 : 1800,
            'sub_groups_ttl' => env('APP_ENV') === 'testing' ? 0 : 900,
            'bom_ttl' => env('APP_ENV') === 'testing' ? 0 : 600,
        ],
        'brands' => [
            // VIN World Manufacturer Identifier (chars 1-3) => brand key.
            'wmi' => [
                'WMW' => 'mini',
                'WBA' => 'bmw', 'WBS' => 'bmw', 'WBY' => 'bmw', 'WBX' => 'bmw', '4US' => 'bmw', '5UX' => 'bmw',
                'WVW' => 'vw', 'WV1' => 'vw', 'WV2' => 'vw', '1VW' => 'vw', '3VW' => 'vw', '9BW' => 'vw', 'WVG' => 'vw',
                'WAU' => 'audi', 'WA1' => 'audi', 'TRU' => 'audi',
                'VSS' => 'seat',
                'TMB' => 'skoda',
                'VF1' => 'renault',
                'UU1' => 'dacia',
                'VF3' => 'peugeot',
                'VF7' => 'citroen',
                // Opel / Vauxhall (Stellantis PSA catalog). VXK = Zaragoza plant (e.g. Corsa).
                'W0L' => 'opel', 'W0V' => 'opel', 'W0S' => 'opel', 'W0X' => 'opel', 'VXK' => 'opel',
                'ZFA' => 'fiat',
                'WDB' => 'mercedes', 'WDD' => 'mercedes', 'WDC' => 'mercedes', 'W1K' => 'mercedes', 'W1N' => 'mercedes',
                'YV1' => 'volvo', 'YV4' => 'volvo',
                'WP0' => 'porsche', 'WP1' => 'porsche',
                'SAJ' => 'jaguar',
                'SAL' => 'landrover',
                // MAN Truck & Bus (decode path live-proven on man_parts / p5man).
                'WMA' => 'man', 'WMB' => 'man',
            ],
            // brand key => PartsLink24 catalog service + p5 group prefix (from the manufacturers endpoint).
            'catalogs' => [
                'mini' => ['service' => 'mini_parts', 'group' => 'p5bmw'],
                'bmw' => ['service' => 'bmw_parts', 'group' => 'p5bmw'],
                'vw' => ['service' => 'vw_parts', 'group' => 'p5vwag'],
                'audi' => ['service' => 'audi_parts', 'group' => 'p5vwag'],
                'seat' => ['service' => 'seat_parts', 'group' => 'p5vwag'],
                'skoda' => ['service' => 'skoda_parts', 'group' => 'p5vwag'],
                'renault' => ['service' => 'renault_parts', 'group' => 'p5renault'],
                'dacia' => ['service' => 'dacia_parts', 'group' => 'p5renault'],
                'peugeot' => ['service' => 'peugeot_parts', 'group' => 'p5psa'],
                'citroen' => ['service' => 'citroen_parts', 'group' => 'p5psa'],
                'opel' => ['service' => 'psa_opel_parts', 'group' => 'p5psa'],
                'fiat' => ['service' => 'fiatp_parts', 'group' => 'p5fiat'],
                'mercedes' => ['service' => 'mercedes_parts', 'group' => 'p5daimler'],
                'volvo' => ['service' => 'volvo_parts', 'group' => 'p5volvo'],
                'porsche' => ['service' => 'porsche_parts', 'group' => 'p5vwag'],
                'jaguar' => ['service' => 'jaguar_parts', 'group' => 'p5jlr'],
                'landrover' => ['service' => 'landrover_parts', 'group' => 'p5jlr'],
                'man' => ['service' => 'man_parts', 'group' => 'p5man'],
            ],
            // Shared-platform families for careful multi-catalog decode (session cost: short lists only).
            'families' => [
                'psa' => ['opel', 'peugeot', 'citroen'],
                'vwag' => ['vw', 'audi', 'seat', 'skoda', 'porsche'],
                'bmw' => ['mini', 'bmw'],
                'renault' => ['renault', 'dacia'],
                'jlr' => ['jaguar', 'landrover'],
            ],
        ],
    ],
];
