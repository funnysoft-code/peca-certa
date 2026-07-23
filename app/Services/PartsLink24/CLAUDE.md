# PartsLink24 Service

- `PartsLink24Catalog` (contract) resolves VIN + category to OE parts for the legacy identify fan-out path.
- `PartsLink24HttpClient` is the bound catalog implementation (`AppServiceProvider::register()`) and drives the real API via `PartsLink24Client`.
- `FakePartsLink24Catalog` remains for tests that bind it explicitly.
- `PartsLink24Client` is the low-level HTTP surface for F7T-48 agent tools: `searchByVin`, `decodeVin`, `listMainGroups`, `listSubGroups`, `listBomParts`, `getPartInfo`.
- Real API: login → authorize (Bearer JWT) → `/{group}/extern/…` GETs. Single concurrent session (`squeezeOut: true`).
- Dedicated app login is live (F7T-106). App-side serialization remains mandatory: every job that hits PL24 must take `SupplierSessionLock::partsLink24()` (shared key across `IdentifyAgentJob`, `IdentifyOePartsJob`, and future PL24 work).
- Credentials via `config('suppliers.partslink24.*')`, never `env()` directly.
- All HTTP goes through `pendingRequest()`: browser UA/headers/Client Hints, optional `proxy`, optional HTTP/2, login `device` from config (never `os: server`).
- Dual login: `login_strategy` auto → username `admin` uses appgtw nested login; everyone else uses portal `POST /auth/ext/api/1.1/login` (flat `account/user/password`) where non-admin squeezeOut works.
- Session life: optional HTML warm-up → login → think → authorize; cookies stored on `PartsLink24Token` and sent with catalog GETs when enabled.
- Catalog GETs: min-gap + skewed jitter + per-minute rate limit + hour/day volume budgets; decode/main/sub/BOM TTL caches (`cache.*_ttl`, 0 = off).
- Residual: full Chrome JA3 still requires a real browser/curl-impersonate edge (not Guzzle).
- `PartsLink24Brand` + `VinBrandResolver` map VIN WMI → catalog service/group.
- Recon + frozen tool contract: `docs/partslink24/recon.md`, `docs/partslink24/agent-tool-contract.md`.
