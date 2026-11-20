# Scout — business leads and job crawler

A Laravel 13 workspace for bounded public-web research. Scout follows links on a selected hostname, extracts business contacts and job listings, stores source-backed results, and exports CSV. It includes an operator dashboard, Redis queue worker, scheduled recrawls, cancellation, crawl history, and retention commands.

## Run locally with Docker

Requirements: Docker Desktop with Docker Compose and an available port 8080.

```sh
cp .env.example .env
# Generate a new app key without installing PHP locally.
docker run --rm php:8.4-cli php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
# Paste that output into APP_KEY in .env before starting.
docker compose up --build -d --wait

docker compose exec app php artisan crawler:user you@example.com --name="Your name"
# Enter a new password at the hidden prompt (minimum 12 characters).

docker compose exec app php artisan db:seed --class=CrawlTargetSeeder
```

Open http://localhost:8080 and sign in. The optional target seeder creates Automattic contact and Python.org job-board targets. It does not start any crawls or create demo results.

The stack includes the app, MySQL 8.4, Redis, a queue worker, and the scheduler. MySQL and Redis are not published to the host. Local development credentials in `compose.yaml` are intentionally local-only. Keep `.env`, app keys, accounts, logs, and data volumes out of Git.

Application files are copied into the image. **Rebuild after code changes:**

```sh
docker compose up --build -d --wait
# Stop containers without removing data:
docker compose down
```

Named volumes preserve MySQL, Redis, and app storage. `docker compose down -v` deletes that local data; use it only when intentionally resetting the workspace.

## Use the workspace

1. Create a target: URL, business/job type, page limit, link depth, and optional schedule.
2. Start a crawl. A second start reuses the existing active run for that target.
3. Watch the run's page activity. Active pages refresh every 10 seconds.
4. Search results by text, target, type, or expiry. Open a result to inspect its fields and sources.
5. Export the currently filtered results to CSV. Spreadsheet formula prefixes are escaped.

Accounts created with `crawler:user` are trusted operators in a **shared workspace**. Every operator can manage all targets and results. Ordinary users cannot enter the dashboard; there is no public registration, multi-tenant isolation, or password-reset email flow.

## Command-line use

Prefix commands below with `docker compose exec app` when using Docker.

```sh
php artisan crawler:user you@example.com --name="Your name"
php artisan crawl:targets
php artisan crawl:targets create --name="Example" --url="https://example.com/contact/" --type=business
php artisan crawl:targets update 1 --max-pages=10 --max-depth=1 --enabled=1
php artisan crawl:targets disable 1
php artisan crawl:fetch 1     # One starting page; reports status and byte count.
php artisan crawl:start 1     # Queue a complete bounded run.
php artisan crawler:schedule # Queue due work and recover abandoned/paused runs.
php artisan crawler:prune --dry-run
php artisan crawler:prune --days=30
```

Editing a target in the dashboard cancels its old active run. The CLI target commands update settings; existing runs keep a snapshot of their starting settings, and disabled targets stop at the next page boundary.

## Extraction and crawl boundaries

- Default target limits are 25 page URLs and depth 2. Pending URLs are capped at 100; complete runs have a ten-minute processing budget.
- HTTP/HTTPS only, standard ports, exact hostname, no credentials, no HTTPS downgrades. Each connection validates all resolved addresses, rejects local/private/reserved ranges, and pins cURL to a validated IP. The OS resolver governs DNS lookup timing; HTTP connection/transfer limits apply to the transport.
- Each page and redirect must satisfy robots rules. The robots parser supports matching groups, longest paths, allow-on-tie, wildcards, end markers, percent-encoded paths, and crawl-delay. Robots redirects are deliberately stricter than the standard: three hops within the selected host. A 404/410 robots file is treated as absent; uncertain, unauthorized, oversized, or non-text rules stop the fetch.
- Requests are serialized per hostname using shared cache locks. Minimum spacing is two seconds; longer robots and Retry-After delays are honored. Excessive waits pause runs. The scheduler resumes eligible work, within the run deadline and page-attempt budget.
- Each transfer uses a five-second connection timeout, a fifteen-second request timeout, a two-MiB page body limit, a 512-KiB robots limit, and at most two additional attempts. Encoded/compressed responses are rejected rather than decompressed without limits.
- Link discovery resolves relative links and same-host base URLs, removes fragments and common tracking parameters, respects nofollow, and skips downloads. Meaningful query parameters and trailing slashes are preserved. Canonical tags are advisory and do not suppress other URLs. Job targets remain under their starting path.
- Business extraction handles Organization/LocalBusiness structured data and conservative contact-page fallbacks. Fallback emails must match the target's domain; unrelated product-support addresses are not attributed to the business. Automattic has a small address fallback.
- Job extraction handles JobPosting arrays and graphs and a Python.org detail-page fallback. Other sites may require their own selectors. Application links are stored but not followed off-host.
- Missing values stay empty. Records are updated by target and stable identity. Business contact/source lists are merged. Jobs with explicit expiry dates can be filtered; a missing job is not declared removed because a bounded crawl may not visit it.
- The crawler supports server-rendered HTML. JavaScript rendering, login-protected sites, global web search, and lead enrichment are outside this release.

## Queues, schedules, cancellation, and recovery

Use a shared Redis or database queue/cache for work across processes. The Docker worker has a 150-second job timeout; queue reservations last 210 seconds so jobs cannot be re-reserved while the worker is still legitimately running them. One job processes one page; durable pending rows and idempotent record updates allow interrupted work to recover.

The scheduler checks due work every minute and runs retention daily at 03:00 UTC. A run abandoned for more than five minutes is requeued. Page recovery attempts are capped at three, in addition to the transport's bounded retries. Expired run budgets terminate instead of scheduling more network work. Failed worker jobs surface as failed crawl runs.

Cancellation stops new work. An in-flight HTTP request may finish, but its records are discarded if cancellation already won the run's database lock. Starting a fresh run is the recovery action for a terminal failed/cancelled run.

Run logs and page metadata are retained for 30 days by default; records not seen for 180 days are pruned. Active runs are preserved. Raw fetched HTML is processed in memory, not retained. Adjust `config/crawler.php` for retention and request limits. Logs are under `storage/logs`; do not put passwords or tokens into target URLs.

## Tests and checks

```sh
composer test
npm ci --ignore-scripts
npm run build
composer validate --strict --no-check-publish
composer audit
npm audit --audit-level=high
```

Container tests must override the persistent database and queue settings:

```sh
docker compose exec -e APP_ENV=testing -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync app php artisan test
```

GitHub Actions installs locked dependencies, builds assets, and runs the suite for pushes and pull requests to `main`. Tests use synthetic fixtures and fake network responses; live acceptance runs are a separate check.

## Troubleshooting

- **No pages move:** check `docker compose logs worker scheduler`, and inspect the run message. For a paused run, respect the stated retry time. Use `crawler:schedule` to run a recovery check manually.
- **Worker timed out:** confirm its timeout remains below queue `retry_after`; restart the worker after configuration changes. Stale running pages recover on the next eligible run job.
- **Robots blocks a site:** choose an allowed source or adjust the target URL. Scout has no bypass switch.
- **Empty data:** inspect page failures and whether the site exposes HTML or structured data. Many sites need a dedicated extractor; empty fields are preferable to invented ones.
- **Database/password errors:** existing MySQL volumes retain their original passwords. Changing Compose environment values does not change a previously initialized database account.
- **Views/assets look old:** rebuild the image, then run `docker compose exec app php artisan optimize:clear`.

## Backups and deployment scope

This repository includes a local development stack, **not a production deployment**. Back up the MySQL database and `.env` separately; keep app keys and credentials in private storage. Restore dumps into a separate test database before relying on backups.

For a future hosted release, configure HTTPS, real secrets, a production web server/PHP runtime, supervised workers/scheduler, tested database backups/restoration, monitoring, and a migration/rollback procedure. The current delivery target is the GitHub codebase; hosting and production verification are intentionally deferred.
