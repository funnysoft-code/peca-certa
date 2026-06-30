# app/Http/Middleware

## Conventions
- `final` classes implementing `Illuminate\Contracts\Http\Middleware` or with a `handle(Request $request, Closure $next)` signature.
- `declare(strict_types=1)`; explicit return types.
- Keep middleware thin: inspect/short-circuit the request, delegate real work to Actions/services.

## Patterns
- Share data to Inertia in `HandleInertiaRequests::share()`; read appearance/sidebar cookies in `HandleAppearance`.

## Anti-Patterns
- Business/write logic in middleware — belongs in an Action.
- Mutating global state per request in a way that breaks under Octane (no static accumulation).
