# MMIT Assessment Admin Tool

A minimal PHP admin UI for the assessment server, deployed alongside the
Phase 3 API at `https://assessment-api.mmitnetwork.com/admin/`.

It lets an authorized admin:

- View every registered user (one row per device fingerprint)
- **Reset** a user's submission so they can retake the assessment
- **Delete** a user record entirely (test data, departed staff)
- **View** a submitted ciphertext and copy it into the local decrypt CLI
- **Mark Decrypted** once the manager has reviewed the plaintext

---

## Files

```
admin/
├── .htaccess              Basic Auth gate + HTTPS redirect
├── _bootstrap.php         Shared: PDO, CSRF, flash, header/footer
├── index.php              User list with sort + action buttons
├── reset.php              POST: clear submission, bump reset_count
├── delete.php             POST: remove user (cascades submission)
├── mark-decrypted.php     POST: stamp submissions.decrypted_at
└── view-ciphertext.php    GET:  display ciphertext for copy/paste
```

`config/config.php` from the Phase 3 API is reused as-is for DB credentials —
no second copy of the connection string.

---

## Setup

### 1. Create the .htpasswd file

The `.htpasswd` file MUST live outside the document root so it cannot be
served as static content. The `.htaccess` expects it at:

```
/home/<cpuser>/.htpasswds/admin/.htpasswd
```

Replace `<cpuser>` in `.htaccess` with the actual cPanel account username
before deploying.

**Option A — cPanel UI (recommended for non-SSH users):**

1. Log in to cPanel for `assessment-api.mmitnetwork.com`.
2. Open **Directory Privacy**.
3. Navigate to `public_html/admin/`.
4. Tick **"Password protect this directory"** and give it a name (e.g.
   "MMIT Assessment Admin"). Save.
5. In the "Create User" section on the same page, add each admin username
   and password. cPanel writes them to the path above automatically.

> cPanel will also rewrite the directory's `.htaccess` to its preferred
> shape. After cPanel touches the file, compare it to the version shipped
> in this repo and re-apply the HTTPS-redirect block and the `_bootstrap.php`
> deny rule if they got dropped.

**Option B — SSH:**

```bash
# First admin (creates the file). The `-c` flag creates; omit it for
# subsequent users so you don't blow the file away.
mkdir -p ~/.htpasswds/admin
htpasswd -c ~/.htpasswds/admin/.htpasswd alice
htpasswd    ~/.htpasswds/admin/.htpasswd bob
chmod 0644 ~/.htpasswds/admin/.htpasswd
```

### 2. Upload the files

Drop the `admin/` directory into `public_html/`. The `.htaccess` will be
picked up by Apache on the next request.

### 3. Verify

Browse to `https://assessment-api.mmitnetwork.com/admin/`. You should:

- Be force-redirected to HTTPS if you typed `http://`
- Get a Basic Auth prompt
- See the user list after authenticating
- See an empty table (until the first registration lands)

### 4. Schema requirements

The admin tool assumes these columns exist on the Phase 3 schema:

**`users`**

| column         | type           | notes                                |
|----------------|----------------|--------------------------------------|
| `device_id`    | CHAR(64), PK   | SHA-256 device fingerprint           |
| `win_username` | VARCHAR        |                                      |
| `computer_name`| VARCHAR        |                                      |
| `registered_at`| DATETIME       |                                      |
| `reset_count`  | INT, default 0 | incremented by `reset.php`           |
| `reset_at`     | DATETIME NULL  | stamped by `reset.php`               |
| `reset_reason` | VARCHAR(500) NULL | populated by `reset.php`          |

**`submissions`**

| column           | type           | notes                              |
|------------------|----------------|------------------------------------|
| `device_id`      | CHAR(64), FK   | references `users.device_id`       |
| `ciphertext_b64` | TEXT/LONGTEXT  | sealed-box ciphertext (base64)     |
| `app_version`    | VARCHAR        |                                    |
| `submitted_at`   | DATETIME       |                                    |
| `decrypted_at`   | DATETIME NULL  | stamped by `mark-decrypted.php`    |

`delete.php` relies on **`ON DELETE CASCADE`** from `submissions.device_id`
to `users.device_id`. If that constraint is not in place, edit `delete.php`
to wrap the deletes in an explicit transaction:

```php
$pdo->beginTransaction();
$pdo->prepare('DELETE FROM submissions WHERE device_id = ?')->execute([$deviceId]);
$pdo->prepare('DELETE FROM users WHERE device_id = ?')->execute([$deviceId]);
$pdo->commit();
```

---

## When to use Reset vs Delete

| Scenario                                                        | Action |
|-----------------------------------------------------------------|--------|
| Candidate took the wrong assessment / chose wrong department    | Reset  |
| Candidate's submission failed in a way that needs a re-take     | Reset  |
| App glitched and a partial submission needs to be cleared       | Reset  |
| Internal test machine submitted real-looking data               | Delete |
| Engineer left the company — clear their row before GDPR window  | Delete |
| Duplicate registration from a re-imaged dev machine             | Delete |

**Rule of thumb:** if the same human should be able to take the assessment
again, use **Reset**. If the row should never have existed, use **Delete**.

### Reset details

- Requires a reason of 10-500 characters (server-validated). The reason is
  stored on the user row in `reset_reason`, alongside `reset_at`. This
  creates a lightweight audit trail.
- `reset_count` increments on every reset. A user row with `reset_count >= 3`
  is a signal worth investigating — either the candidate is gaming retakes
  or the app has a recurring failure mode.
- The original `registered_at` is preserved. Only the submission row is
  cleared, so the user can re-submit without re-registering.

### Delete details

- Requires the admin to type the exact `win_username` to confirm. This is
  re-validated server-side.
- Cascades the submission row (assuming the FK is configured that way).
- Irreversible. There is no archive table — if you need history before
  deletion, capture the ciphertext via View first.

---

## Decrypted-at workflow

Submissions are sealed-box encrypted to a private key that lives **only**
on the manager's local machine. Neither this admin UI nor anyone else with
server access can decrypt them. The workflow is therefore:

1. Manager opens the admin UI and finds a row with `submitted` but no
   `decrypted` marker.
2. Manager clicks **View** on that row.
3. Manager clicks **Copy to clipboard** to grab the ciphertext.
4. Manager opens a local terminal where they have the renderer repo
   checked out and the decrypt private key configured. They run:
   ```
   node tools/decrypt-result.js
   ```
   …then paste the ciphertext when prompted.
5. The CLI prints the plaintext result envelope (department, phase, score,
   answers, timestamps). Manager reviews it.
6. Manager returns to the admin UI and clicks **Mark Decrypted** on the row.
   This stamps `submissions.decrypted_at = NOW()` and removes the row from
   the "needs decrypt" visual queue.

`mark-decrypted.php` only flips the flag when it is currently NULL — a
double click won't overwrite the original timestamp.

---

## Security notes

- **HTTPS-only.** The `.htaccess` 301-redirects HTTP to HTTPS BEFORE
  prompting for Basic Auth, so the password never travels over plaintext.
- **CSRF.** Every mutating action (reset, delete, mark-decrypted) requires
  a session-scoped CSRF token validated with `hash_equals`. The token is
  generated in `_bootstrap.php` and embedded in every form.
- **Strict device_id validation.** All handlers reject any `device_id`
  that doesn't match `/^[a-f0-9]{64}$/i` — short-circuits malformed input
  before it touches PDO.
- **No JS confirm() dialogs.** Reset and Delete use real server-submitted
  forms with required fields (reason, typed-username). This means the
  confirmation copy is part of the rendered HTML and reviewable, not
  trapped in a JavaScript string.
- **Session cookies** are `Secure`, `HttpOnly`, `SameSite=Strict`, scoped
  to `/admin/`, and use a distinct cookie name (`mmit_admin_sid`) so they
  don't collide with anything else served from the host.

---

## A note on the brand palette

The Phase 6 prompt referenced `#1a1a1a` and an "orange accent". The actual
canonical tokens from the renderer app's `css/tokens.css` are
`--mmit-black: #000000` and `--mmit-yellow: #F4C430` / `--mmit-gold: #D4AF37`,
with no orange in the palette. This admin UI uses the canonical tokens to
stay visually consistent with the rest of the assessment app. If the brand
guide has changed and orange is now in scope, update the inline `:root`
block at the top of `_bootstrap.php` accordingly.
