# Harbor Storage

Self-hosted object storage built entirely in Laravel. Store files on your own server, connect applications through a supported S3 API subset, and manage buckets, credentials, quotas, and retained files from the owner console.

Harbor runs without an AWS account or a separate object-storage service. It includes a cPanel deployment workflow for PHP hosting you control.

## Features

- **Storage API:** Signature V4 and presigned URLs, bucket operations, object uploads/downloads, metadata, copy, listing with continuation tokens, and bulk deletion.
- **Large-file handling:** streamed transfers, byte-range downloads, conditional GET/HEAD, and multipart uploads with part-order, ETag, and minimum-size validation.
- **Owner console:** bucket quotas, scoped access keys, object inventory, retained trash, restore/permanent deletion, usage filtering, and searchable audit activity.
- **Recovery and maintenance:** coordinated writes and cleanup, recoverable object publication, expiring multipart uploads, checksum-verified backups, and verified restores into an empty installation.
- **Public pages:** a product landing page and API reference with copyable curl examples.

Harbor implements a focused S3 subset, not the full AWS S3 service. See [Compatibility](#compatibility) before choosing client settings.

## Requirements

- PHP **8.4** is the verified runtime; use the committed Composer lock file to check the requirements for your installation.
- Composer and the PHP extensions required by Laravel, including PDO, Mbstring, Fileinfo, DOM/XML, OpenSSL, and cURL.
- SQLite for local development, or MySQL/MariaDB with PDO MySQL for a typical cPanel deployment.
- Writable private object storage, `storage/`, and `bootstrap/cache/`.
- A scheduler cron entry for automatic retention cleanup.

The current public pages and console use Blade and directly served CSS. Node is not needed to serve them; it is needed for the optional Vite build and local browser smoke script.

## Local installation

These commands are for a **new local installation**. For production installation or updates, use the [cPanel deployment guide](DEPLOYMENT.md).

```sh
git clone https://github.com/MubarekAdem/laravel-s3.git
cd laravel-s3
composer install
cp .env.example .env
php -r "file_exists('database/database.sqlite') || touch('database/database.sqlite');"
php artisan key:generate
php artisan migrate
mkdir -p storage/app/object-store
php artisan db:seed --class=AdminSeeder
php artisan object-store:check
php artisan serve
```

On Windows PowerShell, use `Copy-Item .env.example .env` and `New-Item -ItemType Directory -Force storage/app/object-store` in place of `cp` and `mkdir -p`.

Before running the seed command, set your initial owner in `.env`:

```dotenv
ADMIN_NAME="Storage Owner"
ADMIN_EMAIL=admin@example.com
ADMIN_PASSWORD="choose-your-password"
```

Run `php artisan config:clear` after changing these values. `AdminSeeder` hashes the password and creates the account only if that email does not already exist. It never resets an existing password. `php artisan db:seed` also runs this seeder; it no longer creates Laravel's demo user. Blank email and password skip admin creation. Keep populated credentials out of Git and remove `ADMIN_PASSWORD` after the initial seed if you no longer need it.

For later password recovery, `php artisan object-store:owner admin@example.com --reset` uses hidden password prompts. See [owner recovery](DEPLOYMENT.md#owner-recovery).

Keep `APP_URL` in `.env` consistent with the address used to access the application. The default development URL is `http://localhost:8000`.

### Application routes

| Path | Purpose | Access |
| --- | --- | --- |
| `/` | Product landing page | Public |
| `/api/docs` | API reference and request examples | Public |
| `/login` | Owner sign-in | Guest |
| `/dashboard` | Storage owner console | Authenticated owner |
| `/s3` | Path-style storage API | Signature V4 or supported public reads |
| `/up` | Laravel health endpoint | Public |

Sign in, create a bucket, and issue an API key with the permissions your application needs. Copy the secret when it is displayed; the console does not display it again.

## Connecting an application

Use the server's `/s3` endpoint with **path-style addressing**:

```text
Endpoint:  https://storage.example.com/s3
Object:    https://storage.example.com/s3/media/images/photo.jpg
Service:   s3
Region:    us-east-1 (or OBJECT_STORE_REGION)
Auth:      AWS Signature V4
```

API keys support `read`, `write`, `delete`, and `list` scopes, plus optional bucket restrictions. Keep secrets on a trusted backend. Browser session cookies do not authenticate storage requests.

Open **`/api/docs` on your running Harbor instance** for signed curl examples, the endpoint reference, presigned URL requirements, response formats, limits, and unsupported features. The reference reflects the instance's configured region and limits.

## Configuration

| Variable | Default | Purpose |
| --- | --- | --- |
| `OBJECT_STORE_PATH` | `storage/app/object-store` | Private filesystem directory for object data |
| `OBJECT_STORE_REGION` | `us-east-1` | Region advertised by the API |
| `OBJECT_STORE_MAX_BYTES` | `104857600` | Maximum object or part body, 100 MiB by default |
| `OBJECT_STORE_TRASH_DAYS` | `30` | Retention before scheduled trash cleanup |
| `OBJECT_STORE_MULTIPART_HOURS` | `24` | Inactivity period before multipart cleanup |

The maximum object size also applies to the assembled multipart result. Every selected multipart part except the final part must be at least 5 MiB. Host disk quotas, PHP body limits, and timeouts can impose additional limits.

Keep object data and backups outside every public document root. Preserve `APP_KEY` across updates and restores: encrypted access-key secrets depend on it.

## Operations

### Readiness and cleanup

```sh
php artisan object-store:check
php artisan object-store:purge --dry-run
php artisan object-store:purge-multipart --dry-run
php artisan schedule:list
```

Run Laravel's scheduler every minute. It schedules daily trash cleanup and hourly stale multipart cleanup. The [deployment guide](DEPLOYMENT.md) includes a cPanel cron example, permissions, update steps, and maintenance-mode handling.

### Backups and restore

Create the private backup parent directory first, and use a new destination for each snapshot:

```sh
php artisan object-store:backup /private/backups/snapshot-name
php artisan object-store:restore /private/backups/snapshot-name --verify-only
```

An actual restore requires empty backed-up database tables, an empty object directory, a compatible schema, and the original application key:

```sh
php artisan object-store:restore /private/backups/snapshot-name --force
```

`--force` skips confirmation; it does not allow overwriting a populated installation. Backups contain sensitive data and must stay private. They exclude `.env`, application code, sessions, and orphan files. Keep the original key separately. See the [backup coverage, limits, and restore procedure](DEPLOYMENT.md#restore-into-an-empty-instance).

### Interrupted writes and owner recovery

```sh
php artisan object-store:recover-writes --dry-run
php artisan object-store:recover-writes
php artisan object-store:owner owner@example.com --reset
```

Pending write recovery blocks object access and mutations until reconciled. An interrupted restore has a separate verification procedure documented in the deployment guide. Owner password recovery rotates the remember token and revokes that owner's database sessions when the database session driver is configured.

## Compatibility

Supported operations include bucket creation/listing/HEAD/location/deletion; object PUT/GET/HEAD/copy/deletion; paginated object listing; multipart initiation, part upload, completion, and abort; and multi-object deletion.

Harbor does not currently implement virtual-hosted buckets, IAM/bucket policies, versioning, AWS STS/session tokens, server-side encryption APIs, object tagging/locking, lifecycle APIs, ListParts/ListMultipartUploads, multipart copy, streaming chunk signatures, additional checksum APIs, delimiter/CommonPrefixes listing, or browser form POST uploads. Only the public-read upload flag is supported from the S3 ACL vocabulary. Configure clients for the supported subset described at `/api/docs`.

Storage coordination uses a shared filesystem lock on a single host. Deployments sharing storage must also share the application's persistent lock location; this is not a distributed multi-host storage cluster.

## Development checks

```sh
php artisan test
vendor/bin/pint --dirty
composer audit --locked
node tests/Browser/smoke.mjs
```

The browser smoke check requires Node and an installed Chrome/Edge, or `BROWSER_BINARY` pointing to a Chromium browser. It uses a temporary local SQLite database and checks the landing page, API docs, login, dashboard, and bucket inventory at desktop and mobile widths.

GitHub Actions is configured for SQLite and MySQL. Local verification has used SQLite; MySQL and actual cPanel-host verification must be run in their respective environments. No real AWS integration tests are required.
