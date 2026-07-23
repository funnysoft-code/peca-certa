# F7T-113 spike notes — factory-fit + BOM diagrams

Live recon: 2026-07-23. VIN `WMWSU91010T717700` (MINI R56 LCI JCW). BOM `25` / `25_0444` (gear shift knob / shift lever cover).

## Factory vs greyed (authoritative signal)

PL24 BOM part rows for option packs carry an **`unavailable` key** on the JSON record (UI greys the row). Rows **without** that key are factory-fit for the decoded VIN.

| OE | Description | Signal | Role |
| --- | --- | --- | --- |
| `25117605282` | Leath. gearlever knob w/gaiter/6-speed (SILBER) | no `unavailable` | **Factory** |
| `25117638583` | Gearshift knob, leather/6-speed (GP2) | `unavailable` present; section “John Cooper Works GP” (`P7KHA = Yes`) | **Option / greyed** |

Restriction section headers (often without `partno`) carry human `description` + `values.remark` (SA codes). Client surfaces these as `applicability` on the following part.

**Product mapping**

- `factoryFit = ! array_key_exists('unavailable', $record)`
- `unavailable = array_key_exists('unavailable', $record)`

Do **not** invent greying from CSS or AE alone — the `unavailable` key is the payload signal observed live.

## Illustration assets

BOM response includes `data.images` (page-level) and part-level `hotspotImageIds` / `hotspotId`.

Fixture/live shape:

```json
"images": [{ "id": "_DFLT_", "name": "25_0444" }]
```

Embedded bytes (when present): `data` / `content` / `base64` on the image object (tests use a 1×1 PNG base64).

Follow-up download (when only `id`+`name`): authenticated `GET /{group}/extern/images/vin?serviceName&vin&hg&btnr&imgId&lang` — path reserved for when PL24 returns binary/base64; hard-fail after retries if images array is non-empty but bytes cannot be obtained.

**Null image** only when `data.images` is empty / missing.

## App storage + browser URL

- Disk: `pl24_diagrams` (private root `storage/app/pl24-diagrams` locally; S3 in prod via `PARTSLINK24_DIAGRAMS_DISK=s3`).
- Stored path: content-addressed `diagrams/{sha256}.{ext}`.
- **Browser URL is not `/storage/...`.** That path only serves `storage/app/public`.
- Local / non-S3: `GET /identify/{run}/diagrams/{filename}` (`identify.diagram`), auth + `SearchRunPolicy::view`, stream from private disk.
- S3: `temporaryUrl` when available (`OePartDiagramUrl`).

## Bad production run

- URL: https://finder.r2czauto.pt/identify/019f8ed7-b348-724b-8252-411499a897a0  
- Agent selected `25117638583` from noisy search only (no BOM confirm).  
- Correct factory OE: `25117605282`.

## PL24 auth note (spike day)

During late spike windows, PL24 login returned HTTP 401 for the app account (session/account side). Factory-fit signal was captured earlier the same day while BOM was live. CI uses fixtures only.
