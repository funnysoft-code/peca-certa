# Shop Windows desktop: PL24 egress proxy

Goal: Laravel (local + production) reaches PartsLink24 **through the shop desktop’s public IP**, while the human at that desk keeps using PL24 in the browser on a **separate account**.

This is the preferred identity model:

```text
[Peca Certa local]  ──┐
                      ├── auth proxy ──► shop public IP ──► partslink24.com
[Peca Certa prod]   ──┘                      ▲
                                             │
[Shop browser / human PL24 account] ─────────┘  (direct, no app proxy)
```

---

## 0. Non‑negotiables (clever dual-account setup)

The shop PC already uses PL24 with its **own** account. Treat that as sacred.

| Rule | Why |
| --- | --- |
| **Two PL24 accounts always** | Human seat ≠ app seat. Session is 1 concurrent login **per account**, not per IP. |
| **Never log the app account into the shop browser** | You steal the app seat; identify jobs fail with `USER_ALREADY_LOGGED_IN` / 403. |
| **Never put human credentials in `PARTSLINK24_*`** | App will squeeze/fight the operator mid-work. |
| **Same public IP for both is OK** | Workshops often have many dealers/users behind one NAT. Two accounts, one IP is normal. |
| **Proxy only for the app** | Human Chrome stays direct. Only Laravel → proxy → PL24. |
| **Proxy must require auth** | Shop IP must not become an open relay. |
| **Restrict upstream destinations** | Allowlist `*.partslink24.com` (and required auth hosts) so the box is not a general VPN. |
| **Do not enable global system proxy for Chrome** | Easy to accidentally route human browser through a misconfigured path or break other apps. |

Mental model: **shared building address, two different “employees”**. Clever = isolation by **account + process**, not by inventing a second IP.

---

## 1. What you will install

Recommended for a shop Windows desktop (simple, free, scriptable):

1. **3proxy** (lightweight HTTP/SOCKS proxy) **or** **Caddy** as a forward proxy with basic auth.
2. Optional: **Cloudflare Tunnel** / **Tailscale** / **WireGuard** if the shop has **no public inbound port** (most homes/shops).
3. Optional: Windows Firewall rules locking who can hit the proxy.

This guide uses **3proxy** + **Tailscale** as the default path (zero open router ports, works behind CGNAT). Alternatives at the end.

---

## 2. Decide reachability mode

| Shop network reality | Approach |
| --- | --- |
| You can open a port on the router → shop PC | Direct: `PARTSLINK24_PROXY=http://user:pass@shop-public-ip:3128` |
| No port forward / CGNAT / dynamic IP | **Tailscale** (or Cloudflare Tunnel) between Cloud and shop |
| You already have a shop VPN | Point proxy at that VPN IP |

**Prefer Tailscale** for shop desktops: no firewall drama, identity per machine, easy revoke.

```text
Laravel Cloud  --Tailscale-->  shop-pc:3128 (3proxy)  --ISP-->  PL24
```

Public IP PL24 sees = **shop ISP**, not Laravel Cloud, not Tailscale magic IPs (egress is still the shop default route).

---

## 3. Install Tailscale on the shop PC (recommended)

1. Install [Tailscale for Windows](https://tailscale.com/download/windows).
2. Log in with the **company** Tailscale account (same tailnet as your laptop / deploy machine).
3. Note the machine name and 100.x IP: `tailscale ip -4`.
4. On Laravel Cloud / local, install Tailscale **or** use a subnet router / exit only if you already run that pattern.  
   Simpler for Cloud: run a small always-on relay you control that is on the tailnet **or** use Cloudflare Tunnel (section 8).

If production cannot join Tailscale, use **Cloudflare Tunnel** (section 8) so only Cloudflare → tunnel → localhost proxy, and set:

```env
PARTSLINK24_PROXY=http://user:pass@pl24-proxy.yourdomain.tld:443
```

(or the TCP host you expose).

---

## 4. Install 3proxy on Windows

### 4.1 Download

1. Get a current Windows build of [3proxy](https://github.com/3proxy/3proxy/releases) (zip).
2. Unpack to e.g. `C:\Tools\3proxy\`.
3. Create `C:\Tools\3proxy\cfg\pl24.cfg`.

### 4.2 Config (HTTP proxy, basic auth, PL24-oriented)

```cfg
# C:\Tools\3proxy\cfg\pl24.cfg
# App-only egress. Human Chrome must NOT use this proxy.

daemon
maxconn 32
nserver 1.1.1.1
nserver 8.8.8.8
nscache 65536
timeouts 1 5 30 60 180 1800 15 60

# Strong random password — store in password manager, not chat
users appuser:CL:CHANGE_ME_LONG_RANDOM

# Log for debugging (disable or rotate in production)
log C:\Tools\3proxy\log\pl24.log D
logformat "- +_L%t.%.  %N.%p %E %U %C:%c %R:%r %O %I %h %T"
rotate 7

# Listen only on Tailscale/local interfaces if possible.
# 0.0.0.0 is OK only with strong auth + firewall + no open internet port.
internal 0.0.0.0
external 0.0.0.0

auth strong
allow appuser

# HTTP proxy for Laravel Http client (Guzzle)
proxy -p3128
```

Notes:

- 3proxy’s ACL language can restrict destinations; if your build supports `allow * * *.partslink24.com`, prefer that. If not, rely on app-only use + auth + firewall.
- Do **not** run this as an open `proxy -p3128` without `users` / `auth strong`.

### 4.3 Run once (test)

From elevated PowerShell:

```powershell
cd C:\Tools\3proxy
.\bin\3proxy.exe .\cfg\pl24.cfg
```

From another machine on the tailnet:

```bash
curl -x http://appuser:CHANGE_ME_LONG_RANDOM@SHOP_TAILSCALE_IP:3128 https://www.partslink24.com/ -I
```

Expect HTTP headers back. Then:

```bash
curl -x http://appuser:…@SHOP_IP:3128 https://ifconfig.me
```

Expect the **shop public IP**.

### 4.4 Run as a Windows service (always on)

Options:

1. **NSSM** (Non-Sucking Service Manager): install 3proxy as a service pointing at the cfg.
2. **Task Scheduler**: “At startup”, run `3proxy.exe cfg\pl24.cfg`, user = dedicated service account with “log on as service”.

Shop PC sleep/hibernate will kill egress. Set:

- Power options → **Never sleep** while plugged in (or use a mini PC that stays on).
- Disable “USB selective suspend” if the NIC drops.

---

## 5. Windows Firewall

1. Inbound rule: TCP **3128** allowed **only** from:
   - Tailscale interface / 100.64.0.0/10, **or**
   - Your known office IPs if exposing publicly (discouraged).
2. Outbound: allow 3proxy to HTTPS 443 (default allow is fine).
3. Do **not** open 3128 on the consumer router unless you have no VPN and accept the risk.

---

## 6. Wire Peca Certa (local + production)

Same values everywhere for fingerprint + proxy:

```env
# Shop egress
PARTSLINK24_PROXY=http://appuser:CHANGE_ME_LONG_RANDOM@100.x.y.z:3128

# Fingerprint (from browser-fingerprint-capture.md) — SAME in local + prod
PARTSLINK24_DEVICE_ID=…
PARTSLINK24_DEVICE_OS=Windows
PARTSLINK24_DEVICE_OS_VERSION=10
PARTSLINK24_DEVICE_LANG=pt-PT
PARTSLINK24_DEVICE_OFFSET=60
PARTSLINK24_USER_AGENT=…
PARTSLINK24_ACCEPT_LANGUAGE=pt-PT,pt;q=0.9,en-US;q=0.8,en;q=0.7
PARTSLINK24_APP_VERSION=…

# Dedicated APP account only
PARTSLINK24_ACCOUNT=…
PARTSLINK24_USERNAME=…
PARTSLINK24_PASSWORD=…
PARTSLINK24_SQUEEZE_OUT=false
```

Client already applies `proxy` via Guzzle options in `PartsLink24Client::pendingRequest()`.

### Smoke tests

```bash
# From app host with proxy set (tinker or a one-off)
php artisan tinker --execute 'echo Http::withOptions(["proxy" => config("suppliers.partslink24.proxy")])->get("https://ifconfig.me")->body();'
```

Should print **shop public IP**.

Then run one identify / `decodeVin` after unblock and confirm login 200.

---

## 7. Human browser on the same desktop

### Do

1. Use the **human/shop PL24 account** only in Chrome/Edge.
2. Keep Chrome **system proxy = off** (Settings → System → open proxy settings → ensure no forced proxy unless corporate).
3. Bookmark PL24 for humans; never store app password in browser.
4. If the human must use app data, use **Peca Certa UI**, not the app PL24 credentials.

### Do not

1. Install a browser extension that forces all traffic through 3proxy.
2. Set Windows “Internet Properties” LAN proxy globally for the user you work with all day (breaks random sites; confuses debugging).
3. Share cookies between accounts.
4. Leave the app account logged in “just to check”.

### Same-IP optics (why this is fine)

PL24 sees:

| Traffic | Account | IP | Client |
| --- | --- | --- | --- |
| Chrome | Shop human | Shop ISP | Real browser |
| App via proxy | App dedicated | Shop ISP | Our HTTP client (fingerprinted) |

That looks like **one workshop, two licensed users**. What looks bad is **one account, two simultaneous clients**, or **datacenter IP + `os: server`**.

---

## 8. Alternative: Cloudflare Tunnel (no Tailscale on Cloud)

When Laravel Cloud cannot join Tailscale:

1. On shop PC install `cloudflared`.
2. Create a tunnel that maps a TCP or HTTP hostname to `localhost:3128`.
3. Restrict with Cloudflare Access / tunnel token (only your account).
4. Set `PARTSLINK24_PROXY` to the tunnel endpoint with proxy basic auth still enabled.

Caveat: some tunnel modes re-egress from Cloudflare, which **defeats** shop IP. For **origin IP preservation**, the tunnel must terminate **on the shop PC** and 3proxy must dial PL24 **from that PC’s default route**.  
If your tunnel configuration makes *outbound* PL24 calls from Cloudflare’s network, **stop** — you only gained a reverse path to the proxy, not shop egress. Verify with `ifconfig.me` through the proxy.

```bash
curl -x http://appuser:pass@TUNNEL_HOST:PORT https://ifconfig.me
# MUST equal shop public IP
```

---

## 9. Alternative: Caddy forward proxy

If you prefer Caddy over 3proxy, use a forward-proxy capable build/plugin with basic auth, bind to Tailscale IP, allowlist hosts. Operational idea is identical: **app-only, auth, shop egress, human browser direct**.

---

## 10. Ops checklist

```text
[ ] App PL24 account ≠ human PL24 account
[ ] Human never logs into app account on shop Chrome
[ ] 3proxy (or equivalent) running as service, PC no-sleep
[ ] Proxy requires username/password
[ ] Firewall limits who can reach :3128
[ ] curl via proxy shows shop public IP
[ ] PARTSLINK24_PROXY set on local + prod
[ ] Fingerprint env shared local + prod
[ ] PARTSLINK24_SQUEEZE_OUT=false once seat is free
[ ] SupplierSessionLock still serializes app jobs
[ ] Document password location (1Password / Bitwarden), not in git
```

---

## 11. Failure modes (quick)

| Symptom | Likely cause |
| --- | --- |
| Login `USER_ALREADY_LOGGED_IN` | App account open in a browser somewhere |
| Proxy timeout from Cloud | PC asleep, 3proxy down, Tailscale offline |
| `ifconfig.me` shows Cloud IP | Proxy not applied / empty `PARTSLINK24_PROXY` |
| Human PL24 “kicked” | Someone put human credentials in app env |
| 403 on login | Account block / squeezeOut policy / WAF |
| Intermittent TLS errors | Antivirus HTTPS scan on shop PC |

---

## 12. What this does *not* replace

- Official PL24 commercial integration / IP allowlist (still worth asking the distributor).
- Browser DevTools fingerprint pack (`browser-fingerprint-capture.md`).
- App-side cache, jitter, rate limit (already in `PartsLink24Client`).

---

## Related

- Fingerprint capture handoff: `docs/partslink24/browser-fingerprint-capture.md`
- Session model: `docs/partslink24/session-model.md`
- Client: `app/Services/PartsLink24/PartsLink24Client.php`
