# PartsLink24 recon (F7T-44)

Live recon date: 2026-07-22. Catalog under test: **Mini** (`service=mini_parts`, `group=p5bmw`). VIN: `WMWSU91010T717700` (MINI R56 LCI).

Auth pattern (unchanged):

1. `POST /pl24-appgtw/ext/api/1.0/login` with `{account,user,pwd}`, `squeezeOut: true` (single concurrent session).
2. `POST /auth/ext/api/1.1/authorize` with brand service names → Bearer JWT (`expires_in` ~600s).
3. Catalog GETs with `Authorization: Bearer …`.

`upds=` catalog stamp is optional; Mini endpoints return 200 without it.

## Automatable endpoints (p5bmw / Mini)

| Capability | Method | Path | Key query params | Notes |
| --- | --- | --- | --- | --- |
| VIN part search | GET | `/{group}/extern/search/vin` | `serviceName`, `vin`, `q` (English), `lang` | Proven. Records include OE (`bidata_part_no`), formatted `partno`, name, maingroup (`hg`), subgroup (`fg`), BOM page (`btnr`). Free-text is noisy; first hit often correct. |
| VIN decode / vehicle info | GET | `/{group}/extern/directAccess` | `serviceName`, `q` = VIN, `lang` | `resultStatus=VEHICLE_IDENTIFIED`. Segments: basic, liquid capacities, optional/standard equipment. Summary `description` (model + color). |
| Main groups | GET | `/{group}/extern/groups/main-vin` | `serviceName`, `vin`, `lang` | ~37 groups for Mini (Engine=`11`, etc.). |
| Sub groups / illustrations | GET | `/{group}/extern/groups/func-vin` | `serviceName`, `vin`, `hg`, `lang` | Mix of `sectionrow` headers and BOM pages (`btnr` ids like `11_4574`). |
| BOM / illustration parts | GET | `/{group}/extern/bom/vin` | `serviceName`, `vin`, `hg`, `btnr`, `lang` | Part rows with OE `partno`, formatted number, qty, pos; `link` to partinfo with short `partno` + `pos`. |
| Part detail | GET | `/{group}/extern/partinfo/vin` | `serviceName`, `vin`, `hg`, `btnr`, `partno` (short form from BOM link), `pos` | Requires BOM-link `partno` (not full OE). Wrong params → 400/500. |

## Not automatable / gaps

| Area | Finding |
| --- | --- |
| `pl24-full-vin-data` | Service authorized, but `POST …/vin` returns empty 200. Not usable without reverse-engineering body. |
| `pl24-qparts` | `POST …/search` and `…/parts` return empty 200. Unknown contract. |
| Graph nav | `graphnav/categories/vin` linked from vehicle page; not probed deeply. Browse via main/func groups is enough for v1 tools. |
| Category taxonomy ids | No stable global category tree independent of VIN; groups are VIN-filtered. |
| Brand families beyond Mini | Path family is `/{group}/extern/…` with `group` from config (`p5bmw`, `p5vwag`, `p5psa`, …). Client methods are brand-agnostic via `PartsLink24Brand`. **Live-proven only for Mini/p5bmw.** BMW same group likely similar; VAG/PSA/Renault may differ slightly. |
| Portuguese search | Index is English-only (`q="filtro de óleo"` returns no useful hits). |

## Session / ops

- One concurrent session per PL24 account; app uses `squeezeOut: true` and may evict the operator browser.
- Token cache key: `partslink24.token.{service}` (Cache, Octane-safe).
- 401 on catalog GET → forget cache, re-login/authorize, retry once.
- Default HTTP timeout: `config('suppliers.partslink24.timeout')` (30s).

## Fixtures

Live-captured (redacted) under `tests/Fixtures/PartsLink24/`:

- `authorize.json`
- `search-oil-filter.json`
- `vehicle-direct-access.json`
- `main-groups-vin.json`
- `func-groups-vin-hg11.json`
- `bom-vin-114574.json`
- `partinfo-vin.json`

## Client methods (implemented)

See `docs/partslink24/agent-tool-contract.md` for the frozen F7T-48 tool list mapped onto `PartsLink24Client`.

---

## MAN Truck & Bus recon (F7T-95)

Live recon date: 2026-07-23. Catalog: **MAN** (`service=man_parts`, `group=p5man`). VIN: `WMA06XZZ8HM753386` (TGX 18T 4X2 BL D20/D26).

### Working

| Capability | Endpoint | Result |
| --- | --- | --- |
| VIN decode / vehicle info | `GET /p5man/extern/directAccess` | **Works.** `resultStatus=VEHICLE_IDENTIFIED`, description `TGX 18T 4X2 BL D20/D26..`, basic fields (type, engine, axles, etc.). Fixture: `man-direct-access.json`. |

### Broken / non-car UI paths

| Capability | Endpoint | Result |
| --- | --- | --- |
| Free-text VIN search | `GET /p5man/extern/search/vin` | **HTTP 400.** Server-side Jackson error on `ManSearchRes` / `referenceNo` (response shape differs from Mini/car catalogs). Fixture: `man-search-error.json`. |
| Main groups | `GET /p5man/extern/groups/main-vin` | **HTTP 500** body `{ "demo": false }`. Car-style group browser not available. Fixture: `man-main-error.json`. |
| Func / sub groups | `GET /p5man/extern/groups/func-vin` | **HTTP 500** wrapping 404. Fixture: `man-func-error.json`. |
| BOM | `GET /p5man/extern/bom/vin` | **HTTP 500.** Fixture: `man-bom-error.json`. |

### Interpretation

- MAN uses the same auth + `directAccess` path family as cars, but **search/groups/BOM car paths are not viable** on this account/catalog for the tested VIN.
- Truck UI (Truck / Bus / TGE / Engine selectors in the browser) likely maps to non-`extern/search` / non-`main-vin` flows that we have not reverse-engineered yet.
- Agent impact: `decode_vin` is usable; `search_parts_by_vin` / browse tools return soft `http_error` JSON (F7T-95) instead of killing the job. Operator may need OE from plate/invoice for MAN pricing until truck-specific paths exist.

### Fixtures

Under `tests/Fixtures/PartsLink24/`:

- `man-direct-access.json` (working decode)
- `man-search-error.json`, `man-main-error.json`, `man-func-error.json`, `man-bom-error.json` (status + body snippets)

### Soft-fail contract

Tools catch `RequestException` / transport errors and return:

```json
{ "ok": false, "error": "http_error", "status": 400, "body": "…" }
```

`IdentifyAgentJob` (Tries=1) must not end as empty `failed` solely because a catalog endpoint 4xx/5xx.