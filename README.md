# iDB — Database API proxy (`ccror.php`)

## Purpose

Single PHP file that proxies client requests from the **public MIS server** to the real database API on **192.168.108.89**.

- **Public URL (example):** `https://sakatamalaybalay.com/lcr/iDB/ccror.php`
- **Backend target (default):**  
  `https://192.168.108.89:4433/elcr/eMIS_Web_Connect/iConnect_Database_Target/ccroAPI.php`

No `.env` is deployed with this file.

---

## Install location (CentOS)

```text
/var/www/html/lcr/iDB/ccror.php
```

Copy only **`ccror.php`** from this folder.

```bash
sudo mkdir -p /var/www/html/lcr/iDB
sudo cp ccror.php /var/www/html/lcr/iDB/
sudo chown apache:apache /var/www/html/lcr/iDB/ccror.php
sudo chmod 644 /var/www/html/lcr/iDB/ccror.php
```

---

## Configuration

**Default:** constants inside `ccror.php` point to .89.

**Optional** Apache/PHP environment variables (override without editing file):

| Variable | Meaning |
|----------|---------|
| `CCRO_DB_API_BASE` | Full URL to `ccroAPI.php` on .89 |
| `CCRO_DB_LOG_URL` | Log endpoint on .89 (defaults derived from API base) |

---

## Health check

```text
GET https://<your-mis-domain>/lcr/iDB/ccror.php?health=1
```

Expect JSON indicating backend reachability when .89 is online.

---

## Requirements

- PHP **curl** extension
- Outbound HTTPS to **192.168.108.89:4433**
- CORS headers are set for browser clients; tighten at Apache if policy requires

---

## Do not

- Upload `iConnect_Database_Target` or any `.env` to CentOS
- Point this proxy at a database port directly — always use `ccroAPI.php` on .89

See parent **README.md** and **SECURITY-CERTIFICATE-MIS.md**.
