# Deploying to Hostinger

This app is a standard Laravel 13 application. It runs on **Hostinger shared/Premium/Business
hosting** (PHP + MySQL) — no Node.js needed at runtime because the frontend is pre-built into
static assets. A VPS is only required later if you add queues/websockets.

> PHP requirement: **8.2+** (developed on 8.5). Set the PHP version in hPanel → Advanced → PHP Configuration.

---

## 1. Build assets locally

On your machine (Node is only needed here, not on the server):

```bash
npm install
npm run build            # outputs public/build/
```

Commit `public/build/` or upload it with the files in step 3.

## 2. Create the database (hPanel)

- hPanel → **Databases → MySQL Databases**
- Create a database + user, note the name/user/password/host.

## 3. Upload the project

Upload everything **except** `node_modules/` to your hosting. Two common layouts:

**A. Point the domain document root at `/public`** (cleanest — hPanel → Website → set docroot to `.../public`).

**B. Shared hosting where docroot is fixed to `public_html/`:** put the app one level above
`public_html`, move the contents of `public/` into `public_html/`, and in `public_html/index.php`
fix the two require paths to point at the app folder (`__DIR__.'/../app/vendor/autoload.php'`, etc.).

## 4. Install dependencies & configure

Via SSH (Hostinger Business/VPS) or hPanel terminal:

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Edit `.env`:

```
APP_NAME="CMV Shipping Accounts"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

DB_CONNECTION=mysql
DB_HOST=localhost            # Hostinger usually localhost
DB_DATABASE=your_db
DB_USERNAME=your_user
DB_PASSWORD=your_password

SEED_ADMIN_PASSWORD=change-me-strong
```

> No SSH? Run the artisan steps below by adding a temporary protected route, or use hPanel's
> "Setup" cron to run them once. Prefer SSH where available.

## 5. Migrate, seed, link storage

```bash
php artisan migrate --force
php artisan db:seed --class=DefaultDataSeeder --force   # default masters + super admin
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

Log in with `admin@cmvshipping.com` / your `SEED_ADMIN_PASSWORD`, then **change the password**.

## 6. Import historical data

In the app: **Import Excel** → upload `ACCOUNT WORKBOOK.xlsm` → preview → **Confirm & Import**.

## 7. Daily backup (recommended)

Add a cron job in hPanel → **Advanced → Cron Jobs**:

```bash
mysqldump -u your_user -p'your_password' your_db > ~/backups/cmv-$(date +\%F).sql
```

Schedule it daily. Keep the last ~14 files (add a `find ~/backups -mtime +14 -delete` line).

---

## Setting up a demo instance

A demo is a second, independent copy of this same app — new subdomain, new database, its own
`.env`. No branch and no code changes: branding is data, so the demo simply has different rows in
its `settings` table.

**1. hPanel** — create the subdomain (e.g. `demo.example.com`) with its docroot at
`~/domains/demo.example.com/app/public`, and create a *separate* MySQL database and user.

**2. Code** — `git clone -b phase1-build <repo> app`. `vendor/` and `public/build/` are committed,
so no Composer or Node step is needed to get it running.

**3. `.env`** — its own file, and crucially its own `APP_KEY`:

```
APP_NAME="Hark Creation Accounts"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://demo.example.com

DB_DATABASE=<demo db>
DB_USERNAME=<demo user>
DB_PASSWORD=<demo password>

SEED_ADMIN_EMAIL=demo@harkcreation.com
SEED_ADMIN_NAME="Demo Admin"
SEED_ADMIN_PASSWORD=<demo password>
MAIL_MAILER=log
```

Then `php artisan key:generate`. Never reuse the live `APP_KEY` — a leaked demo key must not
decrypt anything on production.

**4. Bring it up** — `setup-instance.sh` does steps 2–4 and the document-root wiring in one go:

```bash
APP_DIR=~/domains/harkcreation.com/shipaccdemo_app \
DOC_ROOT=~/domains/harkcreation.com/public_html/shippingaccountsdemo \
APP_URL=https://shippingaccountsdemo.harkcreation.com \
APP_NAME="Hark Creation Accounts" \
DB_NAME=u925208630_shipaccdemo DB_USER=u925208630_shipaccdemo \
./setup-instance.sh
```

It prompts for the database password so the secret never reaches shell history. A fresh database
seeds itself with the Hark Creation branding; upload a different logo in **Settings → Company** to
demo for a specific prospect.

> The app is installed **outside** the subdomain folder. A Hostinger subdomain's document root is
> the folder itself, so cloning the repo into it would publish `.env`, `storage/` and `vendor/`
> over HTTP. The script puts the app one level up and points the subdomain folder at the app's
> `public/` — by symlink where possible, otherwise via a small front controller.

After the first install, updates use the normal `./deploy.sh` from `$APP_DIR`.

**5. Sample books and one-click login** — a fresh install has no transactions, so every
dashboard and chart is empty. Seed demo books and the demo account:

```bash
php artisan db:seed --class=DemoDataSeeder
```

Then add to the demo's `.env` and re-cache config:

```
DEMO_MODE=true
```

That puts an **Explore the demo** button on the login page which signs visitors straight in as
`demo@harkcreation.com`, an **accountant** — they can enter and edit data, but Administration
(users, settings, backups, recycle bin, activity log) is hidden *and* refused server-side. Only
the seeded super admin sees it.

`DEMO_MODE` is off by default and the route is not registered without it, so deploying this to a
real installation leaves no passwordless way in.

To clear whatever prospects have entered and start fresh, name the database explicitly:

```bash
DEMO_RESET=u925208630_shipaccdemo php artisan db:seed --class=DemoDataSeeder
```

The seeder refuses to run at all against a database that already holds transactions unless that
name matches, so it cannot damage a live install.

**6. Keep it out of search results** — add a `noindex` header or `robots.txt` on the subdomain so
the demo does not surface next to the real site.

> **Before running any artisan command in the demo folder, check `DB_DATABASE` points at the demo
> database.** `migrate:fresh` against the wrong one is unrecoverable.

### Deploying the white-label change to an existing install

Existing installs keep the branding they already had. The
`2026_08_18_pin_existing_installs_to_current_branding` migration detects a database that already
has settings and pins it to its current logo, link-preview image and sidebar treatment before the
new default can apply — so a normal `./deploy.sh` is all that is needed, and nothing on screen
changes. Snapshots of the previous assets live in `public/brand/`.

---

## Updating later

```bash
git pull                       # or re-upload changed files
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

Rebuild assets locally (`npm run build`) and upload `public/build/` whenever the frontend changes.

## Notes

- File uploads (invoice attachments) are stored on the `public` disk — `php artisan storage:link` is required.
- `maatwebsite/excel` is intentionally **not** used (incompatible with PHP 8.5); import/export uses
  `phpoffice/phpspreadsheet` directly.
