# README-SERVER.md — Deploying the Assessment API on Namecheap cPanel

Step-by-step guide to standing up `https://assessment-api.mmitnetwork.com/`
on a Namecheap cPanel-managed account. Assumes you already own
`mmitnetwork.com` and the DNS for it is managed via this same cPanel
account (or via an external DNS host you can edit).

Estimated time: **30–45 minutes** the first time, including AutoSSL
provisioning wait.

---

## 0. What you'll end up with

```
/home/<cpanel_user>/
├── public_html/
│   └── assessment-api/                ← document root for the subdomain
│       ├── .htaccess
│       └── api/
│           ├── _bootstrap.php
│           ├── health.php
│           ├── register.php
│           └── submit.php
└── assessment-api-config/             ← OUTSIDE public_html, not web-reachable
    └── config.php                     ← real DB credentials and shared secret
```

`schema.sql` and `config/config.example.php` from the repo are reference
files — they don't get deployed to the live server (the .htaccess blocks
.sql and *.example.php anyway).

---

## 1. Create the subdomain

1. Log into cPanel.
2. **Domains → Domains → Create A New Domain**.
3. Domain: `assessment-api.mmitnetwork.com`.
4. **Uncheck** "Share document root" so cPanel creates a dedicated folder.
5. Document Root will default to something like
   `/home/<cpanel_user>/assessment-api.mmitnetwork.com/` or
   `/home/<cpanel_user>/public_html/assessment-api/`. Note the exact path
   — you'll need it in step 4.

DNS: if your domain's nameservers point at Namecheap's cPanel, the A
record is created automatically. If you use external DNS (Cloudflare, Route
53, etc.), add an A record for `assessment-api` pointing at the same IP
as your primary domain. Wait for propagation (`dig assessment-api.mmitnetwork.com`
should return that IP) before continuing — AutoSSL won't issue a cert
otherwise.

---

## 2. Create the MySQL database and user

1. **Databases → MySQL Databases**.
2. Under **Create New Database**, enter `assessment`. cPanel prefixes
   with your account name, so the actual name becomes
   `<cpanel_user>_assessment`. Click **Create Database**.
3. Under **MySQL Users → Add New User**:
   - Username: `apiuser` (becomes `<cpanel_user>_apiuser`)
   - Password: click **Password Generator**, copy the value somewhere
     safe — you'll need it in step 5.
4. Under **Add User To Database**:
   - User: `<cpanel_user>_apiuser`
   - Database: `<cpanel_user>_assessment`
   - Grant: **ALL PRIVILEGES** → **Make Changes**.

---

## 3. Load the schema

1. **Databases → phpMyAdmin**. Select the new database in the left rail.
2. Click the **Import** tab. Choose `schema.sql` from your laptop.
3. Format: SQL. **Go**.
4. Verify three tables now exist: `users`, `submissions`, `api_requests`.

If you prefer the command line, you can also run from cPanel's Terminal:

```
mysql -u <cpanel_user>_apiuser -p <cpanel_user>_assessment < schema.sql
```

---

## 4. Upload the API files

Two ways: **File Manager** in cPanel, or **SFTP** from your laptop.

**Via SFTP** (recommended for repeatability):

```
sftp <cpanel_user>@server.example.com
cd <document-root-from-step-1>
put -r api
put .htaccess
```

Verify the resulting tree:

```
<document_root>/
├── .htaccess
└── api/
    ├── _bootstrap.php
    ├── health.php
    ├── register.php
    └── submit.php
```

Permissions should be 644 for files and 755 for directories (cPanel's
default for SFTP uploads). If you uploaded as 600 anywhere, Apache will
return 403 — fix with `chmod 644 *.php` inside `api/`.

---

## 5. Create the real config file OUTSIDE public_html

1. Connect via SFTP or use the cPanel File Manager.
2. Create directory: `/home/<cpanel_user>/assessment-api-config/`.
   This sits **next to** `public_html/`, not inside it — so Apache will
   never serve files from here.
3. Copy `config/config.example.php` from the repo into that directory as
   `config.php`.
4. Edit `config.php` and fill in:
   - `db.name` → `<cpanel_user>_assessment`
   - `db.user` → `<cpanel_user>_apiuser`
   - `db.password` → the password from step 2
   - `shared_secret` → see step 6
   - `debug` → leave `false`

`chmod 600` the file so only your cPanel user can read it.

---

## 6. Set the shared secret

The bootstrap ships with a placeholder:

```
# in api/_bootstrap.php (you don't edit this file — you set it in config.php)
'shared_secret' => 'REPLACE_WITH_REAL_SHARED_SECRET',
```

Generate a fresh value on your laptop (NOT on the server — the cPanel
shell history is sometimes captured):

```
openssl rand -hex 32
```

Sample output (do **NOT** use this — generate your own):

```
c34311d1de4714ecddff2f3e6f59ac5d308fc9601d77d5bc37b5ca816dda62ab
```

Paste it into `config.php` as `shared_secret`. The desktop client gets
the same value baked in at build time (Phase 4); the two must match
byte-for-byte.

The bootstrap also refuses to authenticate if `shared_secret` is empty
or still starts with `REPLACE_`, so a missed step here fails closed
rather than silently allowing requests.

---

## 7. Provision the TLS certificate

1. **Security → SSL/TLS Status**.
2. Find `assessment-api.mmitnetwork.com` in the list — it should show
   **AutoSSL Domain Validated: No** initially.
3. Click **Run AutoSSL**. Wait 2–5 minutes; refresh.
4. Once the row shows a green padlock, hit `https://assessment-api.mmitnetwork.com/`
   in a browser to confirm the cert is live. (You'll get 403 or 404 since
   there's no index — that's fine, you're checking TLS, not content.)

If AutoSSL fails: the most common cause is DNS not yet resolving.
`dig assessment-api.mmitnetwork.com` from your laptop. If it doesn't
return the cPanel server IP, fix DNS and re-run AutoSSL.

---

## 8. Smoke tests

Run each of these from your laptop. Replace `<SECRET>` with the value
you put in `config.php`.

### 8.1 Health (no auth)

```
curl -i https://assessment-api.mmitnetwork.com/api/health.php
```

**Expect:** `HTTP/2 200` and `{"ok":true,"ver":1}`.

### 8.2 Register — missing secret

```
curl -i -X POST https://assessment-api.mmitnetwork.com/api/register.php \
  -H 'Content-Type: application/json' \
  -d '{"device_id":"0000000000000000000000000000000000000000000000000000000000000000","app_version":"1.0.0","win_username":"u","computer_name":"c","weak_fingerprint":false}'
```

**Expect:** `HTTP/2 401` and `{"error":"unauthorized",...}`.

### 8.3 Register — first call

Pick a fake but well-formed device id (64 hex chars):

```
DID=$(openssl rand -hex 32)
echo "$DID"

curl -i -X POST https://assessment-api.mmitnetwork.com/api/register.php \
  -H 'Content-Type: application/json' \
  -H "X-MMIT-Shared-Secret: <SECRET>" \
  -d "{\"device_id\":\"$DID\",\"app_version\":\"1.0.0\",\"win_username\":\"testuser\",\"computer_name\":\"testbox\",\"weak_fingerprint\":false}"
```

**Expect:** `HTTP/2 201` and `{"user_id":<n>,"submission_state":"registered-no-submission"}`.

### 8.4 Register — duplicate device

Run the same `curl` again with the same `$DID`.

**Expect:** `HTTP/2 409` and `{"submission_state":"registered-no-submission"}`.

### 8.5 Submit — first call

```
# Any base64 stands in as ciphertext for the smoke test; the real client
# sends a sodium.crypto_box_seal output.
CT=$(echo "fake-ciphertext-for-smoke-test" | base64)

curl -i -X POST https://assessment-api.mmitnetwork.com/api/submit.php \
  -H 'Content-Type: application/json' \
  -H "X-MMIT-Shared-Secret: <SECRET>" \
  -d "{\"device_id\":\"$DID\",\"ciphertext\":\"$CT\"}"
```

**Expect:** `HTTP/2 201` and `{"submission_id":<n>,"submission_state":"submitted"}`.
You should receive a notification email at `snoyes@mmitnetwork.com` within
a minute, with subject *"MMIT Assessment submission received"* and a body
mentioning `testuser@testbox` and the last 8 chars of `$DID`.

### 8.6 Submit — duplicate

Run the same submit `curl` again.

**Expect:** `HTTP/2 409` and `{"submission_state":"submitted"}`.

### 8.7 Submit — unregistered device

```
NEWDID=$(openssl rand -hex 32)
curl -i -X POST https://assessment-api.mmitnetwork.com/api/submit.php \
  -H 'Content-Type: application/json' \
  -H "X-MMIT-Shared-Secret: <SECRET>" \
  -d "{\"device_id\":\"$NEWDID\",\"ciphertext\":\"$CT\"}"
```

**Expect:** `HTTP/2 404` and `{"error":"not_registered",...}`.

### 8.8 Register — second call after submit

Run step 8.3 once more with the original `$DID`.

**Expect:** `HTTP/2 409` and `{"submission_state":"submitted"}` (note the
state changed because there's now a submissions row).

### 8.9 Rate-limit check (optional, slow)

```
for i in $(seq 1 35); do
  curl -s -o /dev/null -w "%{http_code}\n" \
    https://assessment-api.mmitnetwork.com/api/health.php
done
```

The first ~30 should be `200`, the rest should be `429` until the
5-minute window slides forward.

---

## 9. Verifying the email path

If 8.5 doesn't deliver to `snoyes@mmitnetwork.com`:

1. cPanel → **Email → Track Delivery**. Find the recent send to
   `snoyes@mmitnetwork.com`. Status should be **Delivered**.
2. If it says **Deferred** or **Failed**, check the reason. The most
   common issues on Namecheap shared hosting:
   - SPF for the From: address (`noreply@assessment-api.mmitnetwork.com`)
     — add a TXT record `v=spf1 include:spf.namecheaphosting.com -all` on
     `assessment-api.mmitnetwork.com`. Or just change the `From:` header
     in `submit.php` to an address on a domain whose SPF you control.
   - DKIM not enabled — cPanel → **Email Deliverability** → enable.
3. cPanel's PHP error log (`Metrics → Errors`) will show any
   `[assessment-api] mail() returned false` entries.

Note: a mail failure does NOT cause the API to return an error. The
submission is durably stored in MySQL regardless; the email is
nice-to-have. Use phpMyAdmin to confirm rows exist in `submissions`
even if the email goes missing.

---

## 10. Going forward

- **Rotating the shared secret**: change `shared_secret` in
  `config.php` and ship a new desktop build with the matching value. The
  two must change together — there's no rolling window.
- **Reading submissions**: use phpMyAdmin to export the `submissions`
  table, then decrypt each `ciphertext_b64` with the matching private
  key via `tools/decrypt-result.js` from the client repo.
- **Backups**: cPanel's nightly backup includes the database. Verify
  this is configured under **Files → Backup**.
- **Monitoring**: the `api_requests` table is a useful audit trail.
  `SELECT endpoint, COUNT(*) FROM api_requests WHERE requested_at >= NOW() - INTERVAL 1 DAY GROUP BY endpoint;`
  gives you a daily traffic snapshot.
