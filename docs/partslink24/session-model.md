# PartsLink24 session model (ops hygiene)

## Single-session constraint

PartsLink24 allows **one concurrent interactive session per account**. The app login payload always sends `squeezeOut: true` (`PartsLink24Client::login`), so each new app auth **evicts any other session** on the same account (operator browser, another worker, another environment).

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
