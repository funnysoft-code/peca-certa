# PartsLink24 session model (ops hygiene)

## Single-session constraint

PartsLink24 allows **one concurrent interactive session per account**.

Login uses **two paths** (`login_strategy: auto` in `config/suppliers.php`):

| Username | Login API | Body | Squeeze for non-admin |
| --- | --- | --- | --- |
| **`admin`** | `POST /pl24-appgtw/ext/api/1.0/login` | nested `authentication` + `device` | often allowed on appgtw |
| **any other** (e.g. ricardo) | `POST /auth/ext/api/1.1/login` (Chrome SPA) | flat `account`, `user`, `password` | **works** with `squeezeOut: true` |

`squeezeOut` field:

| Value | Portal (`auth/1.1`) | Appgtw (`pl24-appgtw/1.0`) |
| --- | --- | --- |
| `true` | **200** `loginStatus=OK` + `sessionToken` (ricardo live-proven) | admin OK; **ricardo often HTTP 403** |
| `false`, free seat | 200 + session | 200 + token/cookies |
| `false`, seat taken | **400** `urn:login:session-limit-exceeded` | **200** `USER_ALREADY_LOGGED_IN` |

Config: `suppliers.partslink24.squeeze_out` (hardcoded default **true**), `login_strategy` (`auto` \| `portal` \| `appgtw`).

**Client behaviour:** try preferred squeeze, then opposite. Portal treats session-limit 400 as “try squeeze”; appgtw treats 403 the same way. No token/cookie after both attempts → clear RuntimeException (soft-failed by tools).

**Ops recovery:**

1. Log out every browser/tab using this PL24 account (or wait for PL24 session TTL).
2. Prefer a **dedicated app-only account** so humans never hold the seat.
3. Deploy the F7T-111 client fix; re-probe login until 200 + non-null `token`.

## Recommended account split

| Role | Account | Used by |
| --- | --- | --- |
| **App** | Dedicated PL24 login (env `PARTSLINK24_*`) | Identify agent jobs, catalog tools, token cache |
| **Operator** | Separate personal / workshop login | Human browser catalog browsing |

Never share the app credentials for day-to-day browser use. Mutual squeezeOut causes 401 thrash, re-auth storms, and empty `failed` identify runs under concurrent load.

## App concurrency model

- Jobs that hit PL24 share `SupplierSessionLock::partsLink24()` (`WithoutOverlapping`) so identify turns serialize on the same token/session.
- Token cache key: `partslink24.token.{service}` (per brand service after authorize).
- On catalog HTTP 401: drop cache, re-login/authorize, retry once; tools soft-fail further errors as JSON (`http_error`).

## Operator impact

If an operator must use the same account temporarily (incident only):

1. Pause identify queue / wait for in-flight jobs.
2. Browse; expect the next job to squeeze them out when it re-auths.
3. Prefer rotating to a dedicated app account ASAP.

## Dynamic catalogs vs config

- **Static:** `config/suppliers.php` brands.wmi + brands.catalogs (deployed).
- **Dynamic:** `storage/app/private/partslink24/dynamic-catalogs.json` via `php artisan partslink24:register-catalog` (no full deploy). Dynamic entries **override** static for the same key/WMI.
- WMI map is a **routing cache/override**, not the sole long-term truth: new account brands can be registered dynamically once service/group are known.

## Residual

True hard isolation under multi-region workers still requires one dedicated app account (and ideally one region) so squeezeOut never fights a human or a second deployment.

## Client fingerprint + shop egress

PL24 anti-automation keys off login `device` payload, HTTP headers/UA, pacing, volume, and egress IP.

| Knob | Config / env | Purpose |
| --- | --- | --- |
| Device id/os/lang | `suppliers.partslink24.device.*` / `PARTSLINK24_DEVICE_*` | Stable non-`server` client identity |
| User-Agent + Accept-Language | `PARTSLINK24_USER_AGENT`, `PARTSLINK24_ACCEPT_LANGUAGE` | Browser-like HTTP surface |
| App version | `PARTSLINK24_APP_VERSION` | Match real SPA when known |
| Proxy | `PARTSLINK24_PROXY` | All PL24 HTTP via shop (or residential) IP |
| Jitter | `PARTSLINK24_JITTER_MS_*` | Pause before each catalog GET |
| Rate limit | `PARTSLINK24_RATE_LIMIT_PER_MINUTE` | Cap catalog GETs per account |
| Response cache | `PARTSLINK24_CACHE_*_TTL` | Cut repeat decode/group/BOM hits |
| HTML warm-up | `PARTSLINK24_SESSION_WARM_UP` | Document GET before login (session life) |
| Cookie continuity | `PARTSLINK24_SESSION_SEND_COOKIES` | Login cookies on catalog GETs + Bearer |
| Client Hints | `PARTSLINK24_SEC_CH_UA*` | Chrome-like `sec-ch-ua` / platform |
| Login extras | `PARTSLINK24_LOGIN_EXTRA` | Extra JSON keys from DevTools body |
| Extra headers | `PARTSLINK24_EXTRA_HEADERS` | Any missing SPA headers |
| HTTP/2 | `PARTSLINK24_HTTP2` | Prefer h2 TLS (not full JA3 spoof) |
| Min gap / think | `PARTSLINK24_MIN_GAP_MS`, `*_THINK_MS_*` | Non-metronomic pacing |
| Hour/day budget | `PARTSLINK24_MAX_PER_HOUR/DAY` | Workshop-like volume optics |
| Business hours | `PARTSLINK24_BUSINESS_HOURS_*` | Optional refuse new sessions off-hours |

**Same fingerprint in prod + local:** copy the same `PARTSLINK24_DEVICE_*`, `USER_AGENT`, `APP_VERSION`, and preferably the same `PARTSLINK24_PROXY` into both envs. One seat, one device, one egress.

**TLS / JA3 residual:** Guzzle cannot fully impersonate Chrome’s TLS fingerprint. HTTP/2 + shop proxy + headers is the in-app ceiling; true JA3 match needs a browser (or curl-impersonate) on the shop box.

### Guides (ops)

| Guide | Path |
| --- | --- |
| Browser DevTools handoff pack (headers, device, HAR, navigator) | `docs/partslink24/browser-fingerprint-capture.md` |
| Shop Windows proxy + dual-account rules (human seat vs app seat) | `docs/partslink24/shop-desktop-proxy.md` |
| Isolated edge pattern vs Zitania / Cloudflare Workers | `docs/partslink24/edge-isolation-vs-zitania.md` |

**Shop proxy vs microservice:** a reverse proxy (or SOCKS) on the workshop network is enough for IP identity; set `PARTSLINK24_PROXY` on Laravel Cloud / local. A separate PL24 microservice only pays off if you need a long-lived session host, queue isolation, or multi-app sharing of one seat. Prefer proxy first; microservice later if session thrash or multi-region still hurts. **Do not** put PL24 session egress on Cloudflare Browser Rendering / CF edge IPs if the goal is “looks like the shop.”

**After account unblock:** capture login + authorize from browser DevTools (`browser-fingerprint-capture.md`) and overwrite UA / `app-version` / any extra headers to match the real SPA.
