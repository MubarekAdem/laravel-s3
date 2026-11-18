# cPanel deployment

Point the storage subdomain document root to `public/`. Set `APP_URL`, MySQL credentials, `OBJECT_STORE_PATH` (a writable path outside `public_html`), `OBJECT_STORE_MAX_BYTES`, and `OBJECT_STORE_TRASH_DAYS` in `.env`.

Run `php artisan key:generate --force`, `php artisan migrate --force`, then `php artisan object-store:owner you@example.com`. Sign in at `/login` to create credentials. Add the cPanel cron entry `* * * * * php /home/USER/storage/artisan schedule:run >> /dev/null 2>&1`.
