# AutoZitania Service

## Conventions

- `final readonly class` for all service classes.
- `declare(strict_types=1)` at the top of every file.
- `AutoZitaniaClient` shells out to the Playwright sidecar (`bin/zitania-search.ts`, run with Bun) because the portal is a DVSE/TOPMOTIVE WebForms catalog with no JSON API.
- The portal allows a single concurrent session per account. Dedicated app login is live (F7T-106), so operator-vs-app contention is gone, but the app must still run **at most one Zitânia worker** for all uses (pricing + future plate/VIN identify). Use `SupplierSessionLock::autoZitania()` only — never a second key.
- The catalog exposes retail (P.V.P.) prices and binary availability only, no purchase prices and no stock quantities.
- Config values accessed via `config('suppliers.autozitania.*')`, never `env()` directly.
- Credentials are passed to the sidecar through explicit `Process` env vars, never as CLI arguments.
