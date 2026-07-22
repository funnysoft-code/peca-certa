# PartsLink24 Service

- `PartsLink24Catalog` (contract) resolves VIN + category to OE parts for the legacy identify fan-out path.
- `PartsLink24HttpClient` is the bound catalog implementation (`AppServiceProvider::register()`) and drives the real API via `PartsLink24Client`.
- `FakePartsLink24Catalog` remains for tests that bind it explicitly.
- `PartsLink24Client` is the low-level HTTP surface for F7T-48 agent tools: `searchByVin`, `decodeVin`, `listMainGroups`, `listSubGroups`, `listBomParts`, `getPartInfo`.
- Real API: login → authorize (Bearer JWT) → `/{group}/extern/…` GETs. Single concurrent session (`squeezeOut: true`).
- Credentials via `config('suppliers.partslink24.*')`, never `env()` directly.
- `PartsLink24Brand` + `VinBrandResolver` map VIN WMI → catalog service/group.
- Recon + frozen tool contract: `docs/partslink24/recon.md`, `docs/partslink24/agent-tool-contract.md`.
