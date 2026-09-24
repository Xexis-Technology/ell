# Deployment — Exotic Lane Limo Phase 1 (Ubuntu 24.04 + Nginx + PHP-FPM + MySQL)

## 1. Provision
- Ubuntu 24.04 LTS, Nginx, PHP 8.3-FPM (extensions: pdo_mysql, mbstring, openssl, curl, zip, fileinfo, gd), MySQL 8, Composer.
- DNS → server. Obtain TLS certs (Let's Encrypt recommended).

## 2. Code
- Copy project to /var/www/ell (exclude .env — create from .env.example).
- `composer install --no-dev --optimize-autoloader`
- Set SITE_URL=https://exoticlanelimo.com (or subdirectory URL — no code changes needed).
- APP_ENV=production, APP_DEBUG=false.

## 3. Nginx
- Use deploy-nginx.conf.example as base: HTTPS + HSTS, deny /storage /config /database /app /views /vendor and /.env, deny uploaded PHP, fastcgi → php8.3-fpm.

## 4. Database
- Create MySQL 8 database + user; import database/database.sql; set DB_* in .env; backups scheduled (e.g. mysqldump daily, 14-day retention).

## 5. Permissions
- storage/logs, storage/cache, storage/uploads, storage/invoices writable by www-data; uploads non-executable (conf already denies).

## 6. Services
- Stripe: set STRIPE_* keys; configure webhook endpoint https://SITE/webhook/stripe.php with STRIPE_WEBHOOK_SECRET.
- SMTP: set MAIL_*; verify a test booking email arrives.
- Maps (optional): set MAPS_ENABLED + GOOGLE_MAPS_API_KEY or leave disabled (awaiting-pricing flow).

## 7. First admin + data
- `php scripts/create_admin.php "Name" "admin@example.com"` (interactive password).
- Admin → Settings/Vehicles/Pricing: verify categories, vehicles, rates, tax, waiting rules, SEO, organization info.

## 8. Go-live checklist (§112)
APP_DEBUG=false; HTTPS+HSTS; secure cookies (auto under HTTPS); .env protected; uploads protected; logs writable-not-public; Stripe webhook configured; SMTP configured; schema loaded; robots.txt/sitemap.xml valid (update {SITE_URL} placeholders); 404/403/500 valid; cron only if added later.

## 9. Local (XAMPP)
- http://localhost/ell with .env SITE_URL=http://localhost/ell, APP_DEBUG=true.
