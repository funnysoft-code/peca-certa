# PartsLink24 browser fingerprint capture (handoff pack)

Use this when the app account is unblocked and you want the Laravel client to **look like your real browser**.

Goal: collect **everything** a human Chrome/Edge session exposes on login + catalog calls so an agent can wire `PARTSLINK24_*` (and code if needed) without guessing.

Do **not** paste real passwords into chat. Redact secrets; keep structure.

---

## Preferred path: real Chrome + agent CDP attach (macOS)

We **can** use your real Google Chrome (not Playwright’s bundled Chromium) by starting Chrome with remote debugging and letting the agent attach via CDP.

```text
You: quit Chrome → start Chrome with --remote-debugging-port=9222
You: open PL24 → log in with APP account (manual)
You: say "ready"
Agent: dev-browser --connect http://127.0.0.1:9222 → dump navigator, headers, login body shape, cookies
You: log out APP account when done
```

### Why this is better than plain dev-browser

| | Real Chrome + CDP | Default dev-browser Chromium |
| --- | --- | --- |
| TLS / JA3 | Your real Chrome | Automation Chromium |
| `navigator.webdriver` | Usually `false` | Often `true` |
| UA / Client Hints | Your real browser | Often headless-ish |
| Extensions / fonts | Your profile (or a dedicated one) | Clean automation profile |

### macOS: start real Chrome for capture

**Important:** Chrome must be **fully quit** first (Cmd+Q). A second instance without the flag will not open 9222 on the already-running process.

**Option A — dedicated capture profile (recommended)**  
Does not touch your daily profile.

```bash
# Quit Chrome completely first (Chrome menu → Quit Google Chrome).
mkdir -p "$HOME/chrome-pl24-capture"
/Applications/Google\ Chrome.app/Contents/MacOS/Google\ Chrome \
  --remote-debugging-port=9222 \
  --user-data-dir="$HOME/chrome-pl24-capture" \
  --no-first-run \
  --no-default-browser-check \
  "https://www.partslink24.com"
```

**Option B — your normal Chrome profile**  
Same machine identity, but Chrome must restart with the flag:

```bash
# Quit Chrome completely first.
/Applications/Google\ Chrome.app/Contents/MacOS/Google\ Chrome \
  --remote-debugging-port=9222
```

Then open `https://www.partslink24.com` yourself.

### Verify CDP is up

```bash
curl -s http://127.0.0.1:9222/json/version | head
```

You should see JSON with `Browser`, `webSocketDebuggerUrl`, etc.

### What you do manually

1. Log in with the **APP** `PARTSLINK24_*` account only.
2. Optionally run one normal catalog action (e.g. open a known VIN / search).
3. Tell the agent: **ready** (leave Chrome open).
4. Do **not** close the tab until the agent finishes.
5. After capture: **log out** of the app account, then quit that Chrome instance.

### What the agent will do (no password typing)

Attach with:

```bash
dev-browser --connect http://127.0.0.1:9222 <<'EOF'
  // list pages, attach to partslink24 tab, evaluate navigator, etc.
EOF
```

Collect: navigator + Client Hints, cookie table, performance/resource entries if available, and (when you keep Network open or the agent can read CDP) login/authorize request shapes.

### Network headers the agent cannot always see after the fact

CDP attach **after** login may miss the original login request body/headers unless:

- You left **DevTools → Network → Preserve log** open and export HAR, **or**
- You re-trigger authorize/catalog after attach, **or**
- You paste **Copy as cURL** for login/authorize from Network yourself.

Best hybrid:

1. Real Chrome + CDP for navigator / cookies / live session.  
2. One HAR or two Copy-as-cURL (login + authorize) from DevTools for exact request headers/body.

### Security notes

- Port `9222` is **local only** by default; do not expose it on the public internet.
- Stop capture Chrome when done (`Cmd+Q` on that instance).
- Dedicated `--user-data-dir` avoids agents walking your personal tabs/history.

---

## 0. Rules before you start

1. Use the **app-only PL24 account** (the one in `PARTSLINK24_*`), not the shop desktop human account.
2. Prefer a **clean profile** (or the dedicated `chrome-pl24-capture` dir) so headers are not polluted by adblock/password managers, unless you intentionally want your daily Chrome identity (Option B).
3. Prefer the **shop Windows desktop Chrome/Edge** if that is the “real workshop” look we want to clone. Local Mac Chrome is fine if that is what you always use for the app account (and what production should mimic).
4. One tab, one login. Do not open PL24 twice with the same account.
5. After capture, **log out** of the app account in that browser so the app seat is free.

---

## 1. Environment snapshot (5 minutes)

Create a text file (or paste sections into chat) with:

```text
## Environment
- OS: Windows 11 / macOS 15 / … (exact)
- Browser: Chrome 131 / Edge 131 / … (chrome://version or edge://version)
- Browser language UI: pt-PT / en-US / …
- Timezone: Europe/Lisbon (UTC+0 or +1 with DST note)
- Screen: 1920x1080 (optional)
- Public IP (from https://ifconfig.me or similar): x.x.x.x
- Network: shop fibre / home / mobile hotspot
- Account role: APP (env PARTSLINK24_*) — not shop human
- Date/time of capture: 2026-…
```

Also note:

```text
- Do you use VPN? yes/no
- Any corporate proxy already on the PC? yes/no
- Antivirus HTTPS scanning (Kaspersky, ESET, etc.)? yes/no
```

---

## 2. DevTools setup

1. Open `https://www.partslink24.com` (or your usual PL24 entry URL).
2. **F12** → **Network**.
3. Enable:
   - **Preserve log**
   - **Disable cache**
4. Filter: `Fetch/XHR` (and later `All` for document navigations if needed).
5. Optional but useful: right‑click column headers → enable **Method**, **Status**, **Type**, **Size**, **Time**.

---

## 3. Capture A — Login

1. Clear Network log.
2. Log in with the **app** account.
3. Find the request whose path contains:

   ```text
   /pl24-appgtw/ext/api/1.0/login
   ```

4. Click it → export **Request Headers**, **Request Payload**, **Response Headers**, **Response body** (redact secrets).

### 3.1 What to copy for Request Headers

Copy the **full** Request Headers block. Especially these (names may vary slightly):

| Header | Why we need it |
| --- | --- |
| `User-Agent` | Client UA string |
| `Accept` | Content negotiation |
| `Accept-Language` | Browser language prefs |
| `Accept-Encoding` | Usually `gzip, deflate, br, zstd` |
| `Content-Type` | JSON vs form |
| `Origin` | SPA origin |
| `Referer` | Landing / login page |
| `sec-ch-ua` | Client Hints brand list |
| `sec-ch-ua-mobile` | `?0` / `?1` |
| `sec-ch-ua-platform` | `"Windows"` / `"macOS"` |
| `sec-fetch-site` | `same-origin` / `same-site` / `cross-site` |
| `sec-fetch-mode` | `cors` / `navigate` |
| `sec-fetch-dest` | `empty` / `document` |
| `sec-fetch-user` | If present |
| `Cache-Control` / `Pragma` | Rare but real |
| `Cookie` | Names only if sensitive; see cookies section |
| Any `x-*` or `pl24-*` custom headers | Critical if present |

Paste as:

```http
### LOGIN request headers
User-Agent: …
Accept: …
Accept-Language: …
…
```

### 3.2 What to copy for Request Payload (JSON body)

Full JSON, with password redacted:

```json
{
  "authentication": {
    "account": "REDACTED",
    "user": "REDACTED",
    "pwd": "***"
  },
  "device": {
    "id": "…",
    "os": "…",
    "offset": "…",
    "lang": "…",
    "os-version": "…"
  },
  "app-version": "…",
  "squeezeOut": false
}
```

**Copy every key**, even ones we do not use yet (`clientId`, `fingerprint`, nested objects, etc.).

### 3.3 Response

```http
### LOGIN response status
HTTP/1.1 200

### LOGIN response headers (full)
Set-Cookie: …
…
```

```json
### LOGIN response body (token values redacted to first/last 4 chars)
{
  "token": "abcd…wxyz",
  "refreshToken": "…",
  "status": "OK"
}
```

List **cookie names** set on login (values can be shortened):

```text
PL24TOKEN=… (length N)
JSESSIONID=…
…
```

---

## 4. Capture B — Authorize

Immediately after login, find:

```text
/auth/ext/api/1.1/authorize
```

Same treatment: **headers + request body + response headers + body**.

Pay special attention to:

- `Authorization` header (if any, before Bearer exists)
- Cookie jar still attached
- Body fields: `serviceNames`, `serviceCategoryNames`, `withLogin`, anything else
- Response: `access_token`, `expires_in`, `token_type`, extra fields

```http
### AUTHORIZE request headers
…

### AUTHORIZE request body
{ … }

### AUTHORIZE response
{ "access_token": "eyJ…", "expires_in": 600, … }
```

---

## 5. Capture C — One real catalog call

After authorize, use the UI once as a human would (example: open a known VIN / Mini oil filter search).

Capture **one successful** JSON call such as:

```text
/extern/search/vin
/extern/directAccess
/extern/groups/main-vin
```

For that request, export:

1. Full **Request URL** (with query string).
2. Full **Request Headers**.
3. Response status + first ~2 KB of JSON (structure only is enough).

This teaches us whether catalog GETs need extra headers (`upds`, custom Accept, etc.) beyond Bearer.

```http
### CATALOG GET
GET https://www.partslink24.com/p5bmw/extern/search/vin?…
Authorization: Bearer …
…
```

---

## 6. Capture D — Document / HTML entry (optional but high value)

1. Network filter → **Doc**.
2. Hard reload the PL24 home/app shell after login.
3. Capture the main document:

   - Request headers for the HTML navigation
   - Response `Set-Cookie` / `Content-Security-Policy` if present
   - Any redirect chain (302 URLs)

This helps if PL24 ever ties sessions to a prior HTML hit.

---

## 7. Cookies inventory

In DevTools → **Application** → **Cookies** → `https://www.partslink24.com` (and parent domains if listed):

| Name | Domain | Path | Secure | HttpOnly | SameSite | Expires | Value length |
| --- | --- | --- | --- | --- | --- | --- | --- |
| PL24TOKEN | … | / | yes | yes | Lax | … | 128 |

Values: either omit, or give first 4 + last 4 chars.

Also list any cookies on related hosts (`auth.`, CDN, etc.).

---

## 8. Client Hints + JS environment (copy/paste)

On the PL24 page, open **Console** and run (safe, no secrets):

```js
(() => {
  const nav = navigator;
  const data = {
    userAgent: nav.userAgent,
    language: nav.language,
    languages: [...(nav.languages || [])],
    platform: nav.platform,
    hardwareConcurrency: nav.hardwareConcurrency,
    deviceMemory: nav.deviceMemory,
    maxTouchPoints: nav.maxTouchPoints,
    cookieEnabled: nav.cookieEnabled,
    doNotTrack: nav.doNotTrack,
    webdriver: nav.webdriver,
    vendor: nav.vendor,
    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
    timezoneOffsetMinutes: new Date().getTimezoneOffset(),
    screen: {
      width: screen.width,
      height: screen.height,
      availWidth: screen.availWidth,
      availHeight: screen.availHeight,
      colorDepth: screen.colorDepth,
      pixelDepth: screen.pixelDepth,
    },
    devicePixelRatio: window.devicePixelRatio,
    locale: Intl.DateTimeFormat().resolvedOptions().locale,
  };
  if (nav.userAgentData) {
    data.userAgentData = {
      brands: nav.userAgentData.brands,
      mobile: nav.userAgentData.mobile,
      platform: nav.userAgentData.platform,
    };
  }
  console.log(JSON.stringify(data, null, 2));
  copy(JSON.stringify(data, null, 2)); // Chrome: copies to clipboard
  return data;
})();
```

Paste the JSON as:

```text
### navigator snapshot
{ … }
```

Optional high-value Client Hints (Chrome):

```js
(async () => {
  if (!navigator.userAgentData?.getHighEntropyValues) return null;
  const v = await navigator.userAgentData.getHighEntropyValues([
    'architecture',
    'bitness',
    'model',
    'platformVersion',
    'fullVersionList',
    'uaFullVersion',
    'wow64',
  ]);
  console.log(JSON.stringify(v, null, 2));
  copy(JSON.stringify(v, null, 2));
  return v;
})();
```

---

## 9. HAR export (best single artifact)

If you can attach a file:

1. Network → right‑click → **Save all as HAR with content**.
2. Open the HAR in a text editor and **search/replace** password fields and long tokens.
3. Name it something like `pl24-login-authorize-search-redacted.har`.
4. Share the HAR + a short note of which requests matter (login, authorize, one search).

HAR is the gold standard: order, cookies, timings, headers, bodies in one pack.

---

## 10. “Copy as cURL” pack (agent-friendly)

For **login**, **authorize**, and **one catalog** request:

1. Right‑click request → **Copy** → **Copy as cURL** (bash).
2. Paste into a fenced code block.
3. Replace password / bearer / cookie values with placeholders:

```bash
# LOGIN
curl 'https://www.partslink24.com/pl24-appgtw/ext/api/1.0/login' \
  -H 'User-Agent: …' \
  -H '…' \
  --data-raw '{"authentication":{"pwd":"***"},…}'
```

This alone is often enough to implement parity.

---

## 11. Mapping to app env (fill after capture)

After you paste the pack, we map into:

| Capture field | App config / env |
| --- | --- |
| `User-Agent` | `PARTSLINK24_USER_AGENT` |
| `Accept-Language` | `PARTSLINK24_ACCEPT_LANGUAGE` |
| `device.id` | `PARTSLINK24_DEVICE_ID` |
| `device.os` | `PARTSLINK24_DEVICE_OS` |
| `device.os-version` | `PARTSLINK24_DEVICE_OS_VERSION` |
| `device.lang` | `PARTSLINK24_DEVICE_LANG` |
| `device.offset` | `PARTSLINK24_DEVICE_OFFSET` |
| `app-version` | `PARTSLINK24_APP_VERSION` |
| Catalog `lang` query | `PARTSLINK24_LANG` (often still `en` for search quality) |
| Extra headers not in env yet | Code in `PartsLink24Client::browserHeaders()` |
| Extra login body keys | Code in `postLogin()` |

Use the **same** fingerprint values in local + production so PL24 sees one device.

---

## 12. Checklist (paste this back completed)

```text
[ ] Environment snapshot
[ ] Login request headers (full)
[ ] Login request body (pwd redacted, device complete)
[ ] Login response headers + body (tokens redacted)
[ ] Authorize request headers + body
[ ] Authorize response (expires_in + token shape)
[ ] One catalog GET URL + headers
[ ] Cookie table (names/domains/flags)
[ ] navigator + userAgentData JSON
[ ] (Optional) high-entropy Client Hints
[ ] (Optional) HAR redacted
[ ] (Optional) three Copy-as-cURL blocks
[ ] Logged out of app account after capture
```

---

## 13. What not to waste time on

- Screenshots of the UI (unless an error banner appears)
- Full multi‑MB HAR of every image asset (filter XHR first, or trim HAR)
- Capturing the **shop human** account session (different seat; confuses mapping)
- Pasting live production secrets into public channels

---

## Related

- Session / dual-account ops: `docs/partslink24/session-model.md`
- Shop Windows proxy (shared public IP, separate accounts): `docs/partslink24/shop-desktop-proxy.md`
- Client code: `app/Services/PartsLink24/PartsLink24Client.php`
- Config: `config/suppliers.php` → `partslink24.*`
