# AutoDelta Service

## Conventions

- `final readonly class` for all service classes.
- `declare(strict_types=1)` at the top of every file.
- `AutoDeltaClient` handles HTTP communication with the AutoDelta catalog API (auth + trade prices + search).
- `AutoDeltaToken` is a value object holding the cached JWT token and its expiry.
- Token caching uses Laravel Cache; no mutable static state (Octane-safe).
- Config values accessed via `config('services.autodelta.*')` — never `env()` directly.
