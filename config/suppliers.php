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
    ],

    'autozitania' => [
        'entry_url' => env('AUTOZITANIA_ENTRY_URL', 'https://web2.carparts-cat.com/default.aspx?11=102&14=15&1115=1&1281=17=0&10=CB42290652B84321A1D2E66B1FA73DCE102015&12=1400'),
        'username' => env('AUTOZITANIA_USERNAME'),
        'password' => env('AUTOZITANIA_PASSWORD'),
        'bun_binary' => env('AUTOZITANIA_BUN_BINARY', 'bun'),
        'script_timeout' => (int) env('AUTOZITANIA_SCRIPT_TIMEOUT', 120),
    ],
];
