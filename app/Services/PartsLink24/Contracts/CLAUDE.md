# PartsLink24 Contracts

## Conventions

- Interfaces only. Each defines a capability the PartsLink24 service exposes so callers depend on the contract, not a concrete client.
- `declare(strict_types=1)` at the top of every file.
- `PartsLink24Catalog` resolves a VIN plus a part category to genuine (OE) part references (`list<App\Data\OePart>`).
- Bind implementations to these contracts in `AppServiceProvider::register()`. A deterministic fake is bound today; the real HTTP client lands in a later, spike-gated plan.
