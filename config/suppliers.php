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
        'bun_binary' => env('AUTOZITANIA_BUN_BINARY', 'bun'),
        'script_timeout' => (int) env('AUTOZITANIA_SCRIPT_TIMEOUT', 120),
    ],

    'partslink24' => [
        'base_url' => env('PARTSLINK24_BASE_URL', 'https://www.partslink24.com'),
        'account' => env('PARTSLINK24_ACCOUNT'),
        'username' => env('PARTSLINK24_USERNAME'),
        'password' => env('PARTSLINK24_PASSWORD'),
        'timeout' => (int) env('PARTSLINK24_TIMEOUT', 30),
        'lang' => env('PARTSLINK24_LANG', 'en'),
        // Seconds shaved off the authorize token TTL before treating it as expired.
        'token_ttl_buffer' => (int) env('PARTSLINK24_TOKEN_TTL_BUFFER', 30),
        // Max distinct OE candidates fed into the Phase 1 pricing fan-out per identify.
        'max_candidates' => (int) env('PARTSLINK24_MAX_CANDIDATES', 5),
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
                'W0L' => 'opel', 'W0V' => 'opel',
                'ZFA' => 'fiat',
                'WDB' => 'mercedes', 'WDD' => 'mercedes', 'WDC' => 'mercedes', 'W1K' => 'mercedes', 'W1N' => 'mercedes',
                'YV1' => 'volvo', 'YV4' => 'volvo',
                'WP0' => 'porsche', 'WP1' => 'porsche',
                'SAJ' => 'jaguar',
                'SAL' => 'landrover',
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
            ],
        ],
    ],
];
