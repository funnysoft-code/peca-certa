# Live Chrome CDP capture (2026-07-23)

Source: real Google Chrome via `--remote-debugging-port=9222` + `dev-browser --connect` (dedicated profile `~/chrome-pl24-capture`).

**Do not commit secrets.** Tokens below are redacted. Session cookies were live at capture time; log out when finished.

## Browser identity

| Field | Value |
| --- | --- |
| Browser | Chrome/150.0.7871.129 |
| User-Agent | `Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36` |
| `navigator.webdriver` | `false` (good: real Chrome, not automation) |
| platform | MacIntel / userAgentData.platform `macOS` |
| architecture | arm / 64-bit |
| platformVersion | 26.5.2 |
| languages | en-US, en (Chrome UI) |
| timezone | Europe/Lisbon |
| timezoneOffsetMinutes | -60 (→ device offset **60** minutes east of UTC) |
| screen | 1512×982 @ 2x |
| PL24 shell | `https://www.partslink24.com/portal-ui` |

## Client Hints (exact)

```http
sec-ch-ua: "Not;A=Brand";v="8", "Chromium";v="150", "Google Chrome";v="150"
sec-ch-ua-mobile: ?0
sec-ch-ua-platform: "macOS"
```

## Live XHR headers (authorize + portal APIs after reload)

Observed on `POST /auth/ext/api/1.1/authorize` and authenticated GETs:

| Header | Value |
| --- | --- |
| Accept-Language | `pt` (some manufacturer calls also used `en`) |
| Referer | `https://www.partslink24.com/portal-ui` |
| Content-Type | `application/json` (where body present) |
| sec-ch-ua* | as above |
| Authorization | Bearer present on post-login APIs |
| Cookie | not visible on these fetch() requests (Bearer-first SPA) |

Login `POST /pl24-appgtw/ext/api/1.0/login` body was **not** in the buffer (already logged in). Optional follow-up: log out + log in with Network preserve, or Copy-as-cURL for exact device payload.

## Session cookies (names only)

| Name | Notes |
| --- | --- |
| `PL24TOKEN` | session token cookie (len 32 at capture) |
| `PARTSLINK24` | account metadata cookie: `account.login`, `user.login`, `user.locale=pt`, `login.timestamp` |

## localStorage (structure)

| Key | Notes |
| --- | --- |
| `lng` | `pt` |
| `pl24-auth` | Bearer JWT, `expires_in: 600`, `scope: pl24-wmidata pl24-usage`, `session_status: alive` |
| `pl24-settings` | UI chrome flags |
| `pl24-iframe` | empty state bag |
| Usercentrics `uc_*` | consent; language `pt` |

## SSOT in code

All of the above is **hardcoded** in `config/suppliers.php` → `partslink24.*` (not `.env`).

`.env` only keeps:

- `PARTSLINK24_ACCOUNT` / `USERNAME` / `PASSWORD`
- optional `PARTSLINK24_PROXY`
- `PARTSLINK24_DIAGRAMS_DISK`

`APP_ENV=testing` zeroes warm-up delays / rate / volume / cache TTLs for the suite.

## Ops notes

1. **Log out** the capture browser when done so the app seat is free.
2. Capture user was the browser session identity in the `PARTSLINK24` cookie (`user.locale=pt`). Keep **app** credentials only in env; do not share with the shop human Chrome account.
3. If production should look like the **shop Windows** PC instead, re-run capture there and override env (Windows UA + `sec-ch-ua-platform: "Windows"`). Mixing Mac UA with shop Windows is optional; IP can still be shop via proxy.
4. Login device JSON body is still best from a DevTools Copy-as-cURL of `/pl24-appgtw/ext/api/1.0/login` if anti-bot stays strict.

## Raw artifacts (local only)

Written under `~/.dev-browser/tmp/` during capture (may contain secrets; do not commit):

- `pl24-fingerprint-pack.json`
- `pl24-session-deep.json`
- `pl24-network-capture.json`
