# PartsLink24 session model (ops hygiene)

## Single-session constraint

PartsLink24 allows **one concurrent interactive session per account**.

Login payload field `squeezeOut`:

| Value | Behaviour |
| --- | --- |
| `true` | Ask PL24 to evict any other session on this account |
| `false` | Login without eviction (may fail if another session is active, depending on account policy) |

Config: `suppliers.partslink24.squeeze_out` / env `PARTSLINK24_SQUEEZE_OUT` (default **false**).

**F7T-111 diagnosis (live probe, local + prod symptoms):**

| Login attempt | Result |
| --- | --- |
| `squeezeOut: true` | **HTTP 403** `Forbidden` (empty Spring error body) — cannot force-evict |
| `squeezeOut: false`, free seat | **200** with session `token` (+ cookies) |
| `squeezeOut: false`, seat taken | **200** `status=USER_ALREADY_LOGGED_IN`, `token=null`, no cookies → authorize fails |

Credentials are present and recognized (wrong password produces a different failure). The break is **session policy**: the account holds another session (browser, Cloud worker, or previous job), and **API squeeze-out is forbidden** for this account.

**Client behaviour:** default `PARTSLINK24_SQUEEZE_OUT=false`; if config asks for `true` and login returns 403, retry once with `false`. If login is 200 without a token, throw a clear “session already active” error (soft-failed by tools).

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
