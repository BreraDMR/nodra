# Demo deployment: preparation without a host

The hosting itself, any remote/push and public publishing are a separate decision for the
owner; this document prepares everything except that decision.

## What we ship

Two processes and one database on one small machine:

- **API**: Symfony in prod mode (`APP_ENV=prod`, `APP_DEBUG=0`), PHP 8.4. For a demo
  `php -S` behind a reverse proxy is enough, but a small FPM/Caddy setup is the more
  correct choice. The entry point is `public/index.php`.
- **Storefront**: a Next.js production build (`npm run build` + `next start`), with the
  build flag `NEXT_PUBLIC_DEMO_MODE=1` and the runtime variable `API_INTERNAL_URL` (read at
  runtime, not baked into the build).
- **PostgreSQL 16**: a separate demo database; the owner's working stand is never
  published.

## Environment variables (secrets stay out of git)

| Variable | What it sets |
|---|---|
| `DATABASE_URL` | the demo database, e.g. `postgresql://…/app_demo` |
| `APP_SECRET` | a random string for Symfony sessions |
| `APP_ENV` / `APP_DEBUG` | `prod` / `0` |
| `APP_DEMO_LOGIN` | `1` enables the demo account login button |
| `APP_ADMIN_PASSWORD` | the admin password created by the fixtures reset; empty means a random one, printed to the console |
| `NEXT_PUBLIC_DEMO_MODE` | `1` is baked into the storefront build: demo badges |
| `API_INTERNAL_URL` | the API address for the storefront's server-side requests |
| `API_PROXY_URL` | the API address for the browser's `/api/*` calls (a rewrite in `next.config.ts`) |
| `GOOGLE_CLIENT_ID/SECRET/REDIRECT_URI` | leave unset: OAuth is not enabled on the demo |

Mail: keep `MAILER_DSN` as `null://null`. The only email (the welcome message to the demo
account) is simply thrown away, and no real emails go out. There are no payments in the
code.

## Release (tested commands)

The candidate was verified live as a local rehearsal of these commands: an empty `app_demo`
database, then migrations, then fixtures with `APP_ENV=dev` and a password from the
environment; the API in prod mode (`APP_ENV=prod APP_DEBUG=0`,
`php -d variables_order=EGPCS -S 127.0.0.1:8001 -t public public/index.php`) and the demo
build of the storefront (`NEXT_PUBLIC_DEMO_MODE=1`, `next start -p 3001`).
What was checked: the catalog returns 85 cards from the fixtures, the demo badge shows on
the home page, admin login with the password from `APP_ADMIN_PASSWORD` works and the session
holds (`/api/admin/me`), and an order placed through the storefront proxy reached the
receipt (quote 1 690 Kč, free Prague delivery, order `requested/unpaid`, the token opens the
receipt). The working stand was not touched (8 orders before and after); the demo database
was dropped after the rehearsal. Not checked locally: the HTTPS return from Google login
(no TLS, so Google is not enabled on the demo) and a real reverse proxy; both stay on the
checklist for the hosting machine.

```bash
# 1. code and dependencies
git clone <repo> nodra && cd nodra            # or rsync the directory without .git
(cd api && composer install --no-dev --optimize-autoloader)
(cd web && npm ci)
# 2. demo database: empty, then fixtures (seed of 85 cards + admin).
#    Fixtures only live in dev/test (config/bundles.php), so their command
#    runs with APP_ENV=dev; an explicitly set password still beats the known dev password.
(cd api && php bin/console doctrine:database:create && \
  APP_ADMIN_PASSWORD='<admin password>' php bin/console doctrine:migrations:migrate -n && \
  APP_ENV=dev APP_ADMIN_PASSWORD='<admin password>' php bin/console doctrine:fixtures:load --no-interaction)
# 3. prod cache and the storefront build in demo mode
(cd api && APP_ENV=prod php bin/console cache:warmup)
(cd web && NEXT_PUBLIC_DEMO_MODE=1 API_PROXY_URL=https://demo.example.cz \
  API_INTERNAL_URL=http://127.0.0.1:8001 npm run build)
# 4. run (two processes under systemd or supervisord)
(cd api && APP_ENV=prod APP_DEBUG=0 DATABASE_URL=… APP_SECRET=… APP_DEMO_LOGIN=1 \
  php -d variables_order=EGPCS -S 127.0.0.1:8001 -t public public/index.php)
(cd web && API_INTERNAL_URL=http://127.0.0.1:8001 ./node_modules/.bin/next start -p 3001 -H 127.0.0.1)
```

HTTPS terminates at the reverse proxy (Caddy for automatic certificates; nginx + certbot
is the classic option). `cookie_secure: auto` switches to secure cookies by itself behind
HTTPS. Important: `cookie_samesite: strict` in `api/config/packages/framework.yaml` has to
be changed to `lax` if you **turn on Google login**, otherwise the return from
accounts.google.com loses the state cookie and login fails (`google-state`). On a demo
without Google, keep the strict cookies.

## Resetting the demo and rolling back

- **Reset the data without removing the machine:** `APP_ENV=dev APP_ADMIN_PASSWORD=… php bin/console doctrine:fixtures:load --no-interaction`
  recreates the catalog and the admin; visitors' orders disappear, which is exactly what a
  demo wants (without `APP_ENV=dev` the command is unavailable: the fixtures bundle is only
  enabled in dev/test). The alternative is from a dump: `scripts/restore.sh <dump>` (it
  writes only into the `app_restore` copy; for the demo machine call it with `DB=app_demo`).
- **Roll back a release:** keep the previous build as `web/.next-previous` and the previous
  dump; a rollback means stopping the processes, `rm -rf web/.next && mv
  web/.next-previous web/.next`, restoring the dump and starting the processes again.
  Migrations on the demo don't change visitors' data, so rolling back data is just another
  fixtures reset.

## Protections already in place

Rate limits (catalog 240/min, checkout 30/min, logins 10/min, 429 with `Retry-After`), a
JSON log of every `/api/` error from 400 up (`var/log/api-errors.jsonl`), private access
tokens for orders, and the supplier and purchase prices never leave through the public API
(covered by tests). The rate-limit cache pool is file-based, so it fits one machine; with
several instances replace it with Redis.

## Cost and approach (the owner's call)

- A small VPS with 2 vCPU / 4 GB (Hetzner CX22 ~4.5 EUR/month; the Czech Wedos VPS Start
  ~200 Kč/month) is enough: the local measurements (see `docs/perf-local.md`) show the
  database is not the bottleneck even at 1,050 cards with sequential requests (p95 < 25 ms
  per scenario), and demo traffic is tiny.
- A domain is optional; DNS and publishing only by a separate decision of the owner (the
  project's working rules: no remote, push or publishing).
- A demo backup isn't critical (everything is recreated from fixtures), but
  `scripts/backup.sh` works on the demo database too.

## Checklist before publishing (when the owner decides)

1. `APP_SECRET` is freshly generated, `APP_ADMIN_PASSWORD` is your own.
2. Only 443 is open; 8001/3001 listen on 127.0.0.1.
3. `curl -s https://…/api/products?locale=cs | head` returns the catalog; the demo badge
   is visible on the home page; `/admin` asks for credentials.
4. A test order goes through to the receipt, and the email lands in the null transport.
5. `cookie_secure` is actually secure (check in a browser), and the session survives an API
   restart.
