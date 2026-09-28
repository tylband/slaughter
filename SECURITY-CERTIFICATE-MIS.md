# Security & TLS certificates — MIS team

This document covers **two different HTTPS layers** in the CCRO setup. Do not confuse them.

---

## 1. Public MIS website (CentOS — what citizens/staff see)

**Example:** `https://sakatamalaybalay.com/lcr/iMenus/ccrogw.php`

| Topic | Guidance |
|-------|----------|
| Certificate | Use the **existing Sakata / MIS team** certificate already on the CentOS Apache vhost |
| Action | **Do not replace** the production certificate when installing these PHP files unless IT schedules a renewal |
| Private key | Stays on MIS server only; never commit to git or send with this upload package |
| Renewal | Follow your CA process (Let’s Encrypt, commercial CA, or government PKI) before expiry |
| HTTP | Redirect port 80 → 443 in Apache if not already done |

**MIS team checklist after install:**

1. Browser shows padlock on `https://<your-domain>/lcr/iMenus/ccrogw.php`
2. Certificate subject matches your public domain
3. No mixed-content warnings (all assets should load via HTTPS or same gateway paths)

---

## 2. Internal app server (192.168.108.89:4433)

**Example backend:** `https://192.168.108.89:4433/elcr/eMenu/`

| Topic | Guidance |
|-------|----------|
| Certificate | Often **Synology / self-signed** or internal CA |
| CentOS proxies | `ccrogw.php`, `ccror.php`, and `ccror-dyn.php` use cURL with **peer verification disabled** so a self-signed .89 cert does not block LAN proxying |
| Security model | .89 is **not** exposed to the internet; only MIS CentOS (and LAN clients) reach it |
| Staff browsers | If users open .89 **directly**, they may need to accept the cert once per browser |

**Do not** expose port 4433 to the public internet without a proper WAF and certificate strategy.

---

## 3. Secrets on MIS CentOS

| Secret | Location | Handling |
|--------|----------|----------|
| `url_key` in `iMenus/config.php` | Gateway opaque URLs | Treat as **password**; restrict file mode `640`, owner `apache` |
| `gateway_token` | Optional header to backend | Same as above |
| SSL private key | `/etc/pki/...` or Apache `SSLCertificateKeyFile` | Root/apache only |
| Database credentials | **Never** on MIS — only on .89 `.env` |

Rotate `url_key` only with SolArt coordination (old opaque links would break).

---

## 4. Apache SSL snippet (reference only)

Your live vhost already exists. Example structure:

```apache
<VirtualHost *:443>
    ServerName sakatamalaybalay.com
    DocumentRoot /var/www/html

    SSLEngine on
    SSLCertificateFile      /etc/pki/tls/certs/sakatamalaybalay.crt
    SSLCertificateKeyFile   /etc/pki/tls/private/sakatamalaybalay.key
    # SSLCertificateChainFile /etc/pki/tls/certs/sakatamalaybalay-chain.crt

    IncludeOptional /etc/httpd/conf.d/ccro-lcr-gateway.conf
</VirtualHost>
```

Paths and file names **must match** what MIS IT already uses.

---

## 5. Hardening recommendations

1. Block direct web access to `config.php`: `.htaccess` in `iMenus` already denies `config.php` via rewrite; keep `config.php` out of URLs.
2. Keep `ccrogw_error.log` outside public download paths or rotate regularly.
3. Patch CentOS and PHP on a schedule.
4. Restrict SSH to MIS admin IPs.
5. Log review: failed gateway health checks may indicate backend or cert issues.

---

## 6. Compliance note

Public traffic terminates on MIS with the **official** site certificate. Data forwarded to .89 stays on the **government LAN**. Document this in your system security plan when auditors ask where TLS ends.

For certificate problems on the **public** site, contact MIS certificate owners.  
For **backend** (.89) reachability, verify firewall and that Synology HTTPS is up on 4433.
