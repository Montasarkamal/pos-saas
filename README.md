# KAMALTUR POS

PHP-based travel operations and point-of-sale system for managing sales, clients, suppliers, services, refunds, and reports.

## Configuration

1. Copy `.env.example` to `.env`.
2. Fill in the database credentials and application secrets.
3. Point the web server document root to this directory.

Runtime data, database dumps, backups, debug utilities, uploaded files, and production secrets are intentionally excluded from version control.

## Secure deployment

- Deploy the `main` branch to `/public_html/pos` through Hostinger Git.
- Keep `.env` and `uploads/` on the server; they are deliberately not tracked by Git.
- Use file permissions `644` for files and `755` for directories. Restrict `.env` further when the hosting environment permits it.
- Before each production release, create a dated copy of the current application directory and verify `/health.php` after deployment.
- Roll back by restoring the dated directory and its `.env`, then verify login and `/health.php`.

The root `.htaccess` disables directory listings, blocks secrets/backups/developer tools from HTTP access, and adds baseline browser security headers.
