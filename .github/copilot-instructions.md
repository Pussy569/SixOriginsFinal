# Copilot instructions for Six Origins

## Project snapshot

This repository is a PHP + MySQL e-commerce / ordering application for a cafe/food business. The app mixes legacy root-level PHP pages with a newer `app/` structure for shared bootstrap, helpers, services, and view templates. Browser code is plain JavaScript and CSS; there is no frontend build pipeline.

## Build, run, and validation commands

- Local Docker app startup:
  - `Copy-Item .env.example .env`
  - ensure the private local database seed `database/database.sql` exists for a fresh Docker volume
  - `docker compose up --build -d`
  - app URL: `http://localhost:8080` unless `APP_PORT` is changed
- XAMPP / direct PHP setup:
  - `Copy-Item config.sample.php config.php`
  - set local runtime values for `DB_HOST`, `DB_USER`, `DB_PASSWORD`, and `DB_NAME`
- Install project dependencies:
  - `php .\composer.phar install`
  - `npm install`
- Lint the browser scripts:
  - `npm run lint`
  - single file: `npx eslint Js\path\to\file.js --max-warnings=0`
- Validate PHP syntax:
  - single file: `php -l .\path\to\file.php`
  - bulk root PHP check: `Get-ChildItem -Filter *.php | ForEach-Object { php -l $_.FullName }`
- There is no automated PHPUnit/Jest/Mocha suite or application build step configured in this repo. For code changes, use PHP syntax checks plus ESLint, and verify database-backed flows in a local Docker/XAMPP environment.

## High-level architecture

- The app is mostly procedural PHP. Public request handlers, AJAX endpoints, and page controllers are still primarily in root-level files such as `index.php`, `checkout.php`, `orders.php`, and admin pages like `admin_orders.php`.
- Shared runtime bootstrap is under `app/bootstrap/`; `app/bootstrap/app_config.php` creates the MySQLi connection, session, security headers, CSRF handling, and shared helpers. Keep root compatibility wrappers intact when adding new shared code so old include paths still resolve.
- Shared business logic lives in `app/helpers/` and `app/services/`. Inventory and order parsing helpers are especially important because product recipes, option pricing, and packaging deductions use distinct database relationships and custom serialization rules.
- Shared UI templates are in `app/views/`; customer/admin pages use these layouts while root-level controllers remain the public entry points.
- Frontend assets are plain files in `Js/` and `Css/` with no bundler. PWA behavior is provided by `manifest.json` and `service-worker.js`.
- Database definition and change management are split between `database/schema.sql` and `database/migrations/`. The private local seed `database/database.sql` is not committed and must remain local; schema changes for existing installs should be made via explicit, reviewed migrations.

## Conventions that matter for this repo

- Use the shared `$conn` MySQLi connection and prepared statements for any request or session data.
- Keep CSRF checks on state-changing requests, and perform request handling / redirects before rendering HTML via a layout include.
- Use `escapeOutput()` for values rendered into HTML to maintain the app's output escaping patterns.
- Guest carts are tracked by a random `guest_cart_id` stored in the session with the `guest_` prefix. Preserve that identity across catalog, product, cart, and checkout flows; guest cart behavior is intentional and not implicitly tied to authentication.
- `orders.total_products` uses a custom serialized format with `||` separators and item text shaped like `Product Name (quantity x size)`, optionally including `[Preference: ...]` and `[Extras: ...]` suffixes. Any change to this format must be updated in all readers and writers together.
- Product option pricing and stock are separate from product-wide ingredient logic and option-specific packaging logic. When changing product, checkout, or inventory behavior, preserve those distinctions.
- Treat `app/` as the shared extension point, but do not remove legacy root-level compatibility wrappers unless the corresponding callers are intentionally migrated and tested.
- Do not commit local secrets, database dumps, or private environment data. Keep `.env`, local `config.php`, and private SQL seeds out of version control.

## Relevant environment notes

- The admin chatbot integrates with Ollama via `OLLAMA_URL`; Docker defaults to `host.docker.internal:11434` unless overridden in `.env`.
- Email and SMS features depend on Resend and provider credentials configured in `.env`; do not hardcode secrets.
- Site customization and uploads persist under Docker data directories such as `/data/site_customization`, and database schema changes for those features are handled via explicit migrations.
