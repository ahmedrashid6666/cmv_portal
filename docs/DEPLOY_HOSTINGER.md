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
