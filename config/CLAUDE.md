# Configuration Files

## Conventions

- Always include `declare(strict_types=1)` at the top.
- `env()` is ONLY allowed inside config files — everywhere else use `config('key')`.
- Provide sensible defaults in every `env()` call: `env('MAIL_FROM_ADDRESS', 'hello@example.com')`.
- Return a plain PHP array from each config file — no classes, no logic, no side effects.
- Do not create new config files without user approval.
- Config file names are snake_case, matching their `config()` access key.

## Patterns

Config with env defaults:
```php
declare(strict_types=1);

return [
    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name'    => env('MAIL_FROM_NAME', env('APP_NAME', 'Laravel')),
    ],

    'mailer' => env('MAIL_MAILER', 'log'),
];
```

Feature-flag style config:
```php
declare(strict_types=1);

return [
    'enabled'   => env('FEATURE_X_ENABLED', false),
    'threshold' => (int) env('FEATURE_X_THRESHOLD', 100),
    'queue'     => env('FEATURE_X_QUEUE', 'default'),
];
```

## Anti-Patterns

- Using `env()` outside of config files — use `config()` helper instead.
- Omitting default values in `env()` calls when a sensible default exists.
- Hard-coding secrets or credentials directly in config — use `.env`.
- Adding new config files without user approval.
- Missing `declare(strict_types=1)` at the top.
- Including closures, class instantiation, or side effects in config files.
