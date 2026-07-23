# PL24 edge isolation vs Auto Zitania (Cloudflare Workers)

Question: can we isolate PartsLink24 like `workers/zitania-browser`, and should that be a **Cloudflare Worker**?

Short answer:

| Supplier | Needs a real browser? | Best edge location | Cloudflare Workers? |
| --- | --- | --- | --- |
| **Auto Zitania** | Yes (HTML portal) | CF Browser Rendering is a good fit | **Yes** (current pattern) |
| **PartsLink24** | No (JSON `login` / `authorize` / `extern/*`) | **Shop network egress** | **Poor default** for session/IP identity |

---

## Why Zitania is on Cloudflare

`workers/zitania-browser` exists because Auto Zitania is a **browser-shaped** portal:

- Login + search need Playwright.
- CF Browser Rendering gives a managed headless Chrome.
- Isolation: Laravel only POSTs `{ reference }` + service token; secrets and browser state live in the worker.

Detection risk is accepted because there is no clean HTTP API alternative.

---

## Why PL24 is different

Peca Certa already talks to PL24’s **official-ish HTTP JSON** surface:

1. `POST /pl24-appgtw/ext/api/1.0/login`
2. `POST /auth/ext/api/1.1/authorize`
3. `GET /{group}/extern/…`

So the isolation you want is **not** “run Chrome in the cloud.” It is:

1. **One dedicated app account** (human shop account stays on desktop Chrome).
2. **One stable fingerprint** (device + UA + headers).
3. **One non-datacenter egress** (shop IP via proxy).
4. Optional: **one process** that owns the session token so Laravel regions do not thrash login.

(1)–(3) are already designed in-app + `PARTSLINK24_PROXY`.  
(4) is the only real “microservice” upside.

---

## Would a Cloudflare Worker for PL24 help?

### A) Worker as pure HTTP client to PL24 (no browser)

```text
Laravel → Worker (CF IP) → partslink24.com
```

| Pros | Cons |
| --- | --- |
| Same “thin API” isolation pattern as Zitania | Egress is **Cloudflare / datacenter** |
| Central token cache in KV/DO | Often **more** automation-scored than shop ISP |
| No shop PC dependency | TLS/JA3 still not a desktop Chrome |

**Verdict:** worse for anti-bot optics than shop proxy. Only useful if PL24 explicitly allowlists CF IPs (they will not).

### B) Worker + Cloudflare Browser Rendering driving PL24 UI

```text
Laravel → Worker + Playwright → PL24 HTML
```

| Pros | Cons |
| --- | --- |
| True browser automation | We already have JSON APIs (slower, flakier path) |
| Parity with Zitania architecture | CF browser fingerprint + CF IP |
| | Higher cost, harder BOM/diagram stability |
| | Still fights single-session seat |

**Verdict:** more detection-prone and more expensive than HTTP + shop proxy. Use only if HTTP APIs die.

### C) Worker as API gateway to a **shop-side** edge (smart hybrid)

```text
Laravel → CF Worker (auth, routing) → tunnel → shop edge → PL24
```

Worker never dials PL24 itself. Shop edge holds login + fingerprint + shop IP.

| Pros | Cons |
| --- | --- |
| Public API stays on CF | Two moving parts |
| Secrets can stay off Laravel Cloud | Tunnel must not re-egress from CF |
| Same isolation *shape* as Zitania | Overkill until multi-app needs it |

**Verdict:** valid later. Not step one.

### D) Shop-side edge only (Zitania pattern, correct location)

```text
Laravel → shop service (Windows service / small Linux box) → PL24
```

Implements the same contract idea as Zitania:

- `POST /pl24/decode` , `/search`, `/bom`, … with service token
- Edge owns `login` + token cache + rate limit + fingerprint
- Laravel does not store PL24 password in multiple places (optional)

**Verdict:** best “microservice” if you outgrow `PARTSLINK24_PROXY`. Egress stays shop.

---

## Detection ranking (best → worst for “looks like workshop”)

1. **Shop proxy** + app HTTP client + real browser fingerprint (`PARTSLINK24_PROXY`)  
2. **Shop-side PL24 edge service** (session host on shop LAN)  
3. Laravel Cloud **direct** HTTP with good fingerprint (datacenter IP)  
4. CF Worker HTTP client to PL24 (datacenter + CF ASN)  
5. CF Browser Rendering driving PL24 UI (bot browser + CF IP)

Zitania lives in (4)/(5) because it has no (1). PL24 should not copy that location blindly.

---

## Recommended roadmap

| Phase | What | When |
| --- | --- | --- |
| **0** | Fingerprint capture pack | Account unblocked |
| **1** | Shop Windows proxy + dual accounts | Now |
| **2** | Tune headers from DevTools | After capture |
| **3** | Optional shop-side edge service | Multi-region thrash, multi-app seat, or password isolation |
| **4** | CF Worker only as **auth front** to shop edge | If you want CF routing without CF egress |

---

## If we later build a shop edge (sketch)

Mirror Auto Zitania’s *interface*, not its *runtime*:

```text
Authorization: Bearer <SERVICE_TOKEN>

POST /v1/decode   { "vin": "…" }
POST /v1/search   { "vin": "…", "q": "oil filter" }
POST /v1/bom      { "vin": "…", "hg": "11", "btnr": "11_4574" }
```

Implementation options on the shop box:

- Small **Node/Bun** or **PHP** service wrapping the same logic as `PartsLink24Client`
- Windows service + Tailscale / tunnel
- No Playwright unless HTTP breaks

Laravel would gain something like `PARTSLINK24_HTTP_URL` + `PARTSLINK24_HTTP_TOKEN` (same shape as `AUTOZITANIA_HTTP_*`).

---

## Bottom line

- **Zitania-like isolation:** yes, as a product idea (thin API, secrets at the edge, single session owner).
- **Zitania-like Cloudflare Browser Worker:** **no** as the default for PL24. It is more detection-prone for IP/ASN reasons and unnecessary because PL24 already speaks JSON.
- **Clever path:** shop proxy first; shop edge service later; Cloudflare only if it terminates before shop egress, never as the PL24 client itself.

## Related

- `docs/partslink24/shop-desktop-proxy.md`
- `docs/partslink24/browser-fingerprint-capture.md`
- `docs/partslink24/session-model.md`
- `workers/zitania-browser/` (reference isolation for HTML portals)
