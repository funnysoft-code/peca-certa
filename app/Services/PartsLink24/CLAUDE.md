# PartsLink24 Service

- `PartsLink24Catalog` (contract) resolves VIN + category to OE parts.
- `PartsLink24HttpClient` is the bound implementation (`AppServiceProvider::register()`) and drives the real API.
- `FakePartsLink24Catalog` remains in the codebase as a deterministic stand-in for tests that bind it explicitly (decouples the identify flow from HTTP).
- Real API: JSON REST, `POST /auth/ext/api/1.1/login` with `{account, user, password}` to get a token; catalogs under `/pl24-*/ext/api/1.0/`. Single concurrent session limit (like Auto Zitania).
- Credentials via `config('suppliers.partslink24.*')`, never `env()` directly.
- `PartsLink24Brand` (value object) + `VinBrandResolver` route a VIN to the right catalog: the resolver reads the WMI map from `config('suppliers.partslink24.brands.*')`, uppercases the VIN's first 3 characters, and returns the matching brand's service and group, or null for an unknown WMI or a too-short VIN.
