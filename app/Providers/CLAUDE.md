# Providers

## Conventions

- `final class` extending `ServiceProvider` (or `HorizonApplicationServiceProvider` for Horizon).
- `declare(strict_types=1)` at the top of every file.
- `register()` for service container bindings. `boot()` for event listeners, observers, rate limiters, Fortify config, and gate definitions.
- Rate limiters defined in `boot()` via `RateLimiter::for()` keyed by `user_id ?: IP`.
- Model observers registered via `Model::observe(ObserverClass::class)` in `boot()`.
- Fortify views use `Inertia::render()` — no Blade views for auth pages.
- Dashboard gates (`viewHorizon`) check `$user->isAdmin()` (or equivalent role check).
- Password defaults: production requires 12+ chars with mixed case, numbers, symbols, uncompromised; non-production requires 8+ chars.
- `AppServiceProvider::boot()` also handles: `trustProxies('*')`, CSRF-expiry redirect, `encryptCookies` exceptions, `Inertia::handleExceptionsUsing()` for HTTP error codes → shared `error` page.
- Register middleware in `bootstrap/app.php` — not in providers.

## Structure

| Provider | Purpose |
|----------|---------|
| `AppServiceProvider` | Boot: event listeners, observers, rate limiters, Fortify bindings, password rules, proxy trust, Inertia exception mapping |
| `FortifyServiceProvider` | Auth actions, Inertia views, login/2FA rate limiting |
| `HorizonServiceProvider` | Horizon gate authorization (when Horizon is installed) |

## Patterns

Rate limiter with tiered limits:
```php
RateLimiter::for('api', function (Request $request) {
    $user = $request->user();

    return Limit::perMinute($user?->isPro() ? 60 : 10)
        ->by($user?->id ?: $request->ip());
});
```

Inertia exception mapping in `AppServiceProvider::boot()`:
```php
Inertia::handleExceptionsUsing(function (Throwable $e): void {
    if ($e instanceof HttpException && in_array($e->getStatusCode(), [403, 404, 405, 429, 500, 503], true)) {
        Inertia::render('error', ['status' => $e->getStatusCode()])->toResponse(request())->send();
        exit;
    }

    throw $e;
});
```

## Anti-Patterns

- Do not register middleware in providers — use `bootstrap/app.php`.
- Do not use `env()` in providers — use `config()`.
- Do not register Fortify Blade views — use `Inertia::render()`.
- Do not add non-auth event listeners in `FortifyServiceProvider` — use `AppServiceProvider`.
