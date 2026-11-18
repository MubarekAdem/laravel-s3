# Shared hosting deployment

## Hosting requirements

Keep the application at `/home/HOSTUSER/object-store` and point the storage subdomain document root at `/home/HOSTUSER/object-store/public`. Replace all example usernames, domains, database names, and paths below. Keep `.env`, `vendor`, backups, and object data outside **every** public document root. Preserve `public/.htaccess` and enable Apache rewriting. If the host cannot point a domain at `public/`, request that configuration from the host before installing.

Use PHP **8.4** for the domain in your hosting PHP settings and for CLI/cron. This workspace was verified with PHP 8.4.11 and Laravel 13.43.0; `composer.json` allows PHP ^8.3, but the committed lock file and extensions determine the actual hosting requirements. Check each release rather than assuming the host's default `php` is suitable. Laravel needs Ctype, cURL, DOM, Fileinfo, Filter, Hash, Mbstring, OpenSSL, PCRE, PDO, Session, Tokenizer, and XML; this MySQL deployment also needs `pdo_mysql`. See [Laravel deployment requirements](https://laravel.com/docs/13.x/deployment). PDO MySQL is unavailable in the local workspace: local automated tests use SQLite. No real Shared hosting/MySQL deployment or AWS integration was verified here; complete the host checks below before production use.

Use Terminal/SSH as the Shared hosting account, not root. Select the actual host-provided PHP and Composer paths; this PHP path is an example. Invoke Composer through the same PHP binary as Artisan:

```sh
cd /home/HOSTUSER/object-store
PHP=/usr/local/bin/php
COMPOSER=/usr/local/bin/composer
"$PHP" --version
"$PHP" --ini
"$PHP" -m
"$PHP" "$COMPOSER" --version
"$PHP" "$COMPOSER" check-platform-reqs --lock --no-dev
```

Stop on a failed command. Never use `--ignore-platform-reqs`, `composer update`, or `composer run setup` for delivery. Install the committed `composer.lock`. Build frontend assets with `npm ci` and `npm run build` on a build machine with the Node version required by the locked Vite release, and upload `public/build` with its manifest. Exclude any development `public/hot` file from the delivery artifact. Node is not required on Shared hosting when compiled assets are shipped.

## First installation

1. Create a MySQL database and database user in Shared hosting, grant that user the privileges needed for migrations, and note the full account-prefixed names. Confirm HTTPS works for the domain. Upload a release, including the lock file, compiled assets, and dotfiles. Use an empty database for a new installation.
2. If this account already has application data, take and verify a backup before proceeding; use the update procedure instead. Never overwrite an existing `.env` with a template.
3. In the application directory, copy the production template **once** and edit it privately:

```sh
test ! -e .env && cp .env.shared-hosting.example .env
chmod 600 .env
```

Set `APP_URL` to the HTTPS domain, fill in MySQL credentials (quote passwords containing spaces or `#`), and replace `OBJECT_STORE_PATH` with an absolute private directory. Keep `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_DRIVER=database`, and secure cookies enabled. The template uses synchronous queues so no long-running worker is needed. It stores objects locally and requires no AWS account or AWS credentials. `OBJECT_STORE_MAX_BYTES` is the per-object limit; trash and multipart retention control scheduled cleanup. Align PHP/web-server body limits, timeouts, disk quota, and available space with intended upload sizes.

4. Create writable private directories. The PHP process and CLI/cron must run with the account's ownership. These owner-only permissions assume that arrangement; ask the host to provide a narrow group/ACL if PHP runs as another user. Never use `777`.

```sh
mkdir -p /home/HOSTUSER/object-store-data
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chmod 700 /home/HOSTUSER/object-store-data
chmod -R u+rwX,go-rwx storage bootstrap/cache
```

5. Install and initialize. Do not ship local cached configuration in `bootstrap/cache`. Clear stale configuration before generating the first key. Run `key:generate` **only on this first installation**, with a blank `APP_KEY`; if a key exists, preserve it.

```sh
"$PHP" "$COMPOSER" install --no-dev --prefer-dist --optimize-autoloader --no-interaction
"$PHP" "$COMPOSER" check-platform-reqs --no-dev
"$PHP" artisan config:clear --no-interaction
"$PHP" artisan key:generate --force --no-interaction
"$PHP" artisan migrate --force --no-interaction
"$PHP" artisan object-store:owner owner@example.com --name="Storage Owner"
"$PHP" artisan object-store:check --no-interaction
"$PHP" artisan optimize --no-interaction
```

Owner creation requires interactive hidden password entry and confirmation. A nonempty password is required; there is no minimum length. It deliberately cannot run with `--no-interaction`. No password argument or environment variable is accepted by this interactive command. Fix any failing readiness check before opening access. Do not create a public storage symlink for private object data.

Alternatively, create the initial owner from `.env` with `ADMIN_NAME`, `ADMIN_EMAIL`, and `ADMIN_PASSWORD` (nonempty; bcrypt allows at most 72 bytes). Clear configuration with `artisan config:clear`, then run `artisan db:seed --class=AdminSeeder --force --no-interaction` instead of the interactive owner-creation command. The seeder never overwrites an existing email's credentials and does not create a demo user. Leave both email and password blank to skip seeding; partial credentials fail validation. Keep the populated `.env` private, remove the bootstrap password after initial creation if no longer needed, and rebuild cached configuration afterward. Password resets continue to use the explicit interactive recovery command below.

6. Add this Shared hosting cron as the same account, replacing both absolute paths. This runs the configured daily trash purge and hourly abandoned multipart purge. Keep cron error output available during setup; once verified, the example appends it to a private log. Arrange rotation for that log.

```cron
* * * * * cd /home/HOSTUSER/object-store && /usr/local/bin/php artisan schedule:run >> /home/HOSTUSER/object-store/storage/logs/scheduler.log 2>&1
```

Verify `artisan schedule:list`, inspect the private scheduler log after a due run, visit `/up` and `/login` on your configured domain, and sign in. These checks require no AWS traffic. Back up the initialized `.env`/`APP_KEY` securely before issuing access credentials.

## Updates: backup first, preserve APP_KEY

Preserve the exact `APP_KEY` across updates. Stored access-key secrets and encrypted sessions rely on it. Regenerating it can make existing credentials unreadable. Also preserve the database, `.env`, private object root, and persistent application files. Do not run `key:generate`, `migrate:fresh`, or seeders during an update.

1. Prepare the new release and compiled assets separately. Read its migration notes, verify the target PHP/extension requirements with the lock file, and record the current release identifier. Plan a window that stops writes, including cron and any independently configured workers. Disable the Shared hosting scheduler cron temporarily and wait for in-flight uploads and cleanup jobs to finish. Maintenance mode does not stop external workers or direct filesystem writers.
2. Using the **current** release, enter maintenance mode before changing files:

```sh
cd /home/HOSTUSER/object-store
"$PHP" artisan down --retry=60 --no-interaction
```

3. Before replacing code or migrating, make and verify a consistent backup outside all public roots and outside the object root. Use a new, private backup destination for each snapshot:

```sh
mkdir -p /home/HOSTUSER/private-backups
chmod 700 /home/HOSTUSER/private-backups
"$PHP" artisan object-store:recover-writes --dry-run --no-interaction
"$PHP" artisan object-store:backup /home/HOSTUSER/private-backups/pre-update-YYYYMMDD-HHMM --no-interaction
"$PHP" artisan object-store:restore /home/HOSTUSER/private-backups/pre-update-YYYYMMDD-HHMM --verify-only --no-interaction
```

The backup parent directory must exist and the snapshot destination must be new. Resolve any pending write-recovery journals before backup; inspect the dry run and command help while writes remain paused. The backup refuses unresolved journals. Also preserve the original `.env`/`APP_KEY`, current release, persistent application files, and a host-supported MySQL database export (Shared hosting Backup/phpMyAdmin or your host's supported mechanism). Protect backup files and the environment copy with account-only permissions. Review the backup command's manifest/coverage and retain everything required to reconstruct a matching database and object-data snapshot, including trash and unfinished uploads. Verify the database export can be restored. Do not proceed with an unverified backup. If updating from a release without these commands, take an equivalent verified full backup through the host first.
4. Upload/switch the prepared code while retaining `.env`, object data, persistent files, and `storage/framework/down` until verification is complete. If switching release directories, share the persistent storage directory so maintenance mode stays active. Never copy development caches or `public/hot`. Run each command below individually and stop on the first failure:

```sh
"$PHP" "$COMPOSER" install --no-dev --prefer-dist --optimize-autoloader --no-interaction
"$PHP" "$COMPOSER" check-platform-reqs --no-dev
"$PHP" artisan config:clear --no-interaction
"$PHP" artisan migrate --force --no-interaction
"$PHP" artisan object-store:check --no-interaction
"$PHP" artisan object-store:recover-writes --dry-run --no-interaction
"$PHP" artisan optimize --no-interaction
"$PHP" artisan schedule:list --no-interaction
```

Review the write-recovery dry run before any actual recovery. Keep maintenance mode active while investigating unfinished writes; use the release's command help for recovery options. A dry run is inspection, not confirmation that repairs happened.

5. Check command results, application logs, permissions, and the deployed asset manifest. If you separately operate queue workers, restart them under your supervisor after the release is ready (`artisan queue:restart` for queue workers); arrange an appropriate PHP-FPM/OPcache reload with the host if changes are not picked up. When all checks pass:

```sh
"$PHP" artisan up --no-interaction
```

Re-enable the Shared hosting cron, verify `/up`, `/login`, owner sign-in, and scheduled cleanup logging. If anything fails, leave maintenance mode active and cron disabled while fixing it. Do not automatically run `up` in a shell trap after failed migrations. A code-only rollback may be incompatible with completed migrations.

## Restore into an empty instance

Restore into an isolated, empty destination, never over a populated live instance. Keep that destination in maintenance mode with cron/workers stopped. Use a compatible release, configure a new empty database and private empty object root, and restore the **original** `.env` key from its secure backup. Adjust destination paths and database credentials without changing `APP_KEY`. Install dependencies and run the release's migrations to prepare empty application tables; do not create an owner or issue keys before restoring. Inspect command help for release-specific prerequisites.

```sh
"$PHP" artisan object-store:restore /home/HOSTUSER/private-backups/pre-update-YYYYMMDD-HHMM --verify-only --no-interaction
"$PHP" artisan object-store:restore /home/HOSTUSER/private-backups/pre-update-YYYYMMDD-HHMM --force --no-interaction
"$PHP" artisan object-store:check --no-interaction
"$PHP" artisan object-store:recover-writes --dry-run --no-interaction
"$PHP" artisan optimize --no-interaction
```

`--verify-only` validates the backup without restoring data. `--force` permits the restore invocation; it does **not** authorize overwriting a nonempty destination. If the command refuses a populated destination, provision a new empty instance instead of deleting live data. Validate the restored data, original-key decryption, owner login, and application logs before switching traffic, reopening access, or re-enabling cron. Keep the previous instance and backup intact until validation succeeds. For host-level rollback, restore matching code, database, object data, and the original key together.

An interrupted restore leaves a private `.restore-pending.json` marker when files have been published. Object access and mutations remain blocked while it exists. Run `object-store:restore /path/to/the/same/snapshot --verify-restored` to compare restored file checksums and every backed-up database row before clearing the marker. If verification fails, leave that instance isolated and restore again into a new empty instance; do not manually remove the marker. This protects service access across interruption but does not make filesystem and database commits a single atomic operation.

Logical snapshots include users, buckets, objects, access keys, audit events, multipart uploads and parts. They exclude sessions, password-reset tokens, orphan files, application code, and `.env`. Store the original application key separately. Snapshots are private, unsigned data: checksums detect accidental changes, not a malicious party who can replace both the data and manifest. The portable format currently limits the inventory to 20,000 object/part files and 16 MiB of manifest data; use the host's full backup tools for larger installations.

## Local delivery verification

Before packaging, run the focused CLI/configuration tests and the dependency-free browser smoke check from the repository root on a development/build machine:

```sh
php artisan test --compact tests/Feature/CreateOwnerTest.php tests/Feature/DeploymentEnvironmentTest.php
node tests/Browser/smoke.mjs
composer audit --locked
```

The browser check needs an installed Chrome/Edge or the `BROWSER_BINARY` environment variable pointing at a compatible browser executable. It uses temporary local SQLite data and a temporary owner, without touching live data; screenshots go to the ignored `storage/framework/browser-smoke` directory. It does not replace host PHP/MySQL, HTTPS, cron, permissions, or restore checks. Composer audit reported no advisories during delivery preparation; run it again for each release. No actual AWS tests are required or performed by these checks.

## Owner recovery

Restrict shell and Shared hosting access: any shell operator who can run this command can reset a dashboard owner. Take a backup, pause cron, drain requests, and enter maintenance mode for recovery so active requests cannot recreate revoked sessions. Keep the existing `APP_KEY`. Clear stale cached configuration if session/database settings changed, then run:

```sh
"$PHP" artisan object-store:owner owner@example.com --reset
```

Enter the new password twice in the hidden prompts. Invalid email/name, empty passwords, or mismatched confirmation fail without changing credentials. An existing owner requires `--reset`; a nonexistent owner with `--reset` fails to catch address typos. The name stays unchanged unless `--name="New Name"` is supplied. When creating a new owner, the name defaults to `Owner`.

With the default bcrypt hasher, passwords must also fit within 72 bytes and contain no null bytes; multibyte characters use more than one byte. This prevents silent password truncation.

Recovery rotates the remember token and, with `SESSION_DRIVER=database`, deletes only that owner's sessions using the configured `SESSION_CONNECTION` and `SESSION_TABLE`. Failure to delete configured database sessions prevents the password update. The database mutation holds the shared storage mutex so it cannot overlap a backup snapshot; password prompts do not hold the mutex. Other owners and their sessions remain intact. The production template uses database sessions for this reason. For another session backend, the command warns that those sessions were not revoked: invalidate them using the backend's supported tools while access remains paused. Recovery does not revoke independently issued object-store API keys; review/revoke those separately if compromise is suspected.

After success, bring the application up, restore cron, sign in with the new password, and verify the old password no longer works. Never put passwords in shell history, command arguments, deployment scripts, cron, or support tickets. If an existing deployment's key is lost, restore the original key from its secure backup rather than generating a replacement.
