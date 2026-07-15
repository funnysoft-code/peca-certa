# PartsLink24 Service

- `PartsLink24Catalog` (contract) resolves VIN + category to OE parts.
- `FakePartsLink24Catalog` is a placeholder until the real HTTP client (Plan 2).
- Real API: JSON REST, `POST /auth/ext/api/1.1/login` with `{account, user, password}` to get a token; catalogs under `/pl24-*/ext/api/1.0/`. Single concurrent session limit (like Auto Zitania).
- Credentials via `config('suppliers.partslink24.*')`, never `env()` directly.
