# Requirements — MIS CentOS (CCRO eMIS Web Connect)

## Server role

| Item | Requirement |
|------|-------------|
| OS | CentOS 7 / Rocky / AlmaLinux 8+ (64-bit) |
| Role | Public HTTPS **edge** only — proxies to **192.168.108.89:4433** |
| Network | Outbound TCP **4433** (and/or **443**) to app server on LAN |

The MIS server does **not** need direct SQL Server or MySQL if all data flows through the proxies and gateway.

---

## Software

| Component | Minimum | Notes |
|-----------|---------|--------|
| **Apache httpd** | 2.4+ | `mod_rewrite`, `AcceptPathInfo` |
| **PHP** | 7.4+ (8.x OK) | Match `ccrogw.php` check |
| **php-curl** | Required | Gateway and both proxies |
| **php-json** | Required | Health and API JSON |
| **php-mbstring** | Recommended | Some backend responses |
| **OpenSSL** | System | HTTPS termination on MIS |

Install example (RHEL 8+ / Rocky):

```bash
sudo dnf install -y httpd php php-cli php-curl php-json php-mbstring
sudo systemctl enable --now httpd
```

CentOS 7:

```bash
sudo yum install -y httpd php php-cli php-curl php-json php-mbstring
```

---

## Apache settings

| Setting | Value |
|---------|--------|
| `AllowOverride` | **FileInfo** (or **All**) for `/var/www/html/lcr` |
| `AcceptPathInfo` | **On** for gateway path-info URLs |
| `DirectoryIndex` | `ccrogw.php` optional under `iMenus`; `index.php` under `iDyn` |
| SELinux | If enabled, allow httpd network connect: `setsebool -P httpd_can_network_connect 1` |

---

## Disk layout (after install)

```text
/var/www/html/lcr/
├── iMenus/
│   ├── ccrogw.php
│   ├── config.php          ← MIS-editable
│   ├── .htaccess
│   └── apache-gateway.conf ← optional copy to conf.d
├── iDB/
│   └── ccror.php
└── iDyn/
    ├── index.php
    ├── ccror-dyn.php
    └── .htaccess
```

---

## What must stay on 192.168.108.89

| Path on .89 (example) | Purpose |
|------------------------|---------|
| `/elcr/eMenu/` | Portal menu and app catalog |
| `/elcr/evitalNewReact/`, `/elcr/eVital/`, etc. | Applications (via gateway) |
| `/elcr/eMIS_Web_Connect/iConnect_Database_Target/` | Real DB API + **`.env`** |
| `/elcr/Dynamic_Rest_API/` | Dynamic REST backend |

---

## Firewall checklist

**On CentOS (MIS):**

- Inbound **443** (and **80** only if redirecting to HTTPS)
- Outbound to **192.168.108.89:4433**

**On 192.168.108.89:**

- Inbound **4433** from MIS server IP (and staff LAN as policy allows)

---

## Browser / client

- Modern browser (Chrome, Edge, Firefox)
- Staff may need to trust **internal** .89 certificate once when hitting .89 directly; public MIS URL uses the **Sakata** (or MIS) certificate instead.

---

## Files you must NOT deploy on CentOS

- `.env`, database passwords, `client_token.dat`
- Full `iConnect_Database_Target` or `Dynamic_Rest_API` trees from the dev PC (unless explicitly instructed for a dedicated app server — not this package)
