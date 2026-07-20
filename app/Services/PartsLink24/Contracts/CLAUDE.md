# PartsLink24 Contracts

## Conventions

- Interfaces only. Each defines a capability the PartsLink24 service exposes so callers depend on the contract, not a concrete client.
- `declare(strict_types=1)` at the top of every file.
- `PartsLink24Catalog` resolves a VIN plus a part category to genuine (OE) part references (`list<App\Data\OePart>`).
- Bind implementations to these contracts in `AppServiceProvider::register()`. `PartsLink24HttpClient` is bound by default; tests that need deterministic OE numbers bind `FakePartsLink24Catalog` explicitly instead.
