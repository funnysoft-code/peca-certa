# F7T-48 agent tool contract (frozen from F7T-44)

All tools call `App\Services\PartsLink24\PartsLink24Client` after resolving the brand with `VinBrandResolver`. Tools return JSON strings to the LLM. No supplier pricing tools.

**Runtime names:** each tool implements `name()` with the exact snake_case id below. `Laravel\Ai\Tools\ToolNameResolver` uses `name()` when present (otherwise class basename). Keep contract ids, `name()`, and agent instructions in lockstep (see `IdentifyAgentJobTest`).

## Shared failure modes

| Failure | Behavior |
| --- | --- |
| Unknown WMI / short VIN | Tool returns `{ "ok": false, "error": "unsupported_brand", "message", "availableBrands" }` (no HTTP). Identify job pre-gates before the LLM and surfaces `pending_question.kind=unsupported_brand` with catalog options (brand override), not model/year clarification. |
| HTTP 4xx/5xx after retry | Exception bubbles; job marks SearchRun failed (or tool wraps as `{ ok: false, error: "http_error", status }`). |
| Empty result | `{ "ok": true, … empty list }` — not an error. |
| Session eviction / 401 | Client re-auths once; if still failing → http_error. |
| Timeout | Http client timeout (`suppliers.partslink24.timeout`). |

## Session / timeout notes

- Single PL24 session; all tools share the same token cache.
- Per-turn caps (tool call count + wall clock) are enforced in the agent job (F7T-48), not in the client.
- Prefer English part queries for `search_parts_by_vin`.

---

## Tool list

### 1. `resolve_brand`

| | |
| --- | --- |
| **Args** | `vin: string` |
| **Client** | `VinBrandResolver::resolve` |
| **Return** | `{ ok, brandKey, service, group }` or unsupported_brand |
| **Use** | Optional; agent may skip if orchestration already resolved brand. |

### 2. `decode_vin`

| | |
| --- | --- |
| **Args** | `vin: string` |
| **Client** | `PartsLink24Client::decodeVin` |
| **Return** | `{ ok, vin, description, resultStatus, fields: [{description, value}] }` |
| **Failure** | null decode → `{ ok: false, error: "vin_not_identified" }` |
| **Use** | Confirm vehicle (model, year, color) before searching. |

### 3. `search_parts_by_vin`

| | |
| --- | --- |
| **Args** | `vin: string`, `query: string` (English free text) |
| **Client** | `PartsLink24Client::searchByVin` |
| **Return** | `{ ok, results: [{ oe, name, partno, maingroup, subgroup, btnr }] }` |
| **Use** | Primary discovery. Treat multi-hit lists as noisy; use browse/BOM to confirm. |

### 4. `list_main_groups`

| | |
| --- | --- |
| **Args** | `vin: string` |
| **Client** | `PartsLink24Client::listMainGroups` |
| **Return** | `{ ok, groups: [{ id, description }] }` |
| **Use** | Catalog browse when search is ambiguous. |

### 5. `list_sub_groups`

| | |
| --- | --- |
| **Args** | `vin: string`, `mainGroupId: string` (hg, e.g. `"11"`) |
| **Client** | `PartsLink24Client::listSubGroups` |
| **Return** | `{ ok, groups: [{ id, description, kind: section\|bom, btnr }] }` |
| **Use** | Drill into Engine / Brakes / etc. Prefer `kind=bom` rows for `list_bom_parts`. |

### 6. `list_bom_parts`

| | |
| --- | --- |
| **Args** | `vin: string`, `mainGroupId: string`, `btnr: string` |
| **Client** | `PartsLink24Client::listBomParts` |
| **Return** | `{ ok, parts: [{ oe, partno, description, pos, qty, partinfoPartno }] }` |
| **Use** | Exact OE list for an illustration page. `oe` is the pricing reference. |

### 7. `get_part_info`

| | |
| --- | --- |
| **Args** | `vin`, `mainGroupId`, `btnr`, `partinfoPartno`, `pos` (from BOM link) |
| **Client** | `PartsLink24Client::getPartInfo` |
| **Return** | `{ ok, oe, partno, description, fields }` or not_found |
| **Use** | Optional detail when BOM row is unclear. |

---

## Out of tool surface (v1)

- Supplier pricing (Auto Delta / Zitania) — after final OE selection only, outside the agent loop.
- Plate→VIN, Europeças, graphnav, full-vin-data, qparts.
- Operator clarification is **not** a PL24 tool; it is agent structured output / job status `needs_input` (F7T-48).
