# Repository guidance

## Build, test, and lint

- Run the application locally with Docker Compose: copy `.env.example` to `.env`, ensure the private `database/database.sql` is present for a fresh database volume, then run `docker compose up --build -d`. The site is available at `http://localhost:8080` unless `APP_PORT` is changed. See `README.md` for XAMPP setup, deployment, and database setup details.
- For XAMPP, create the ignored local `config.php` from `config.sample.php`. Both runtimes get database credentials from `DB_HOST`, `DB_USER`, `DB_PASSWORD`, and `DB_NAME`.
- Install PHP dependencies with `php .\composer.phar install`. Install JavaScript dependencies with `npm install`.
- Lint browser scripts with `npm run lint`. To lint one script, run `npx eslint Js\path\to\file.js --max-warnings=0`.
- Check a PHP file with `php -l .\path\to\file.php`. For all root-level PHP pages, run `Get-ChildItem -Filter *.php | ForEach-Object { php -l $_.FullName }`.
- There is no configured automated test runner or PHP build step. Validate PHP edits with `php -l` and JavaScript edits with ESLint; exercise database-dependent flows in a configured local environment.

## Architecture

- This is a procedural PHP application backed by MySQL/MariaDB. Most customer, admin, and AJAX request handlers are root-level PHP files; each page usually handles its request and renders its view. Public URLs remain at the root, so keep existing root entry points and compatibility includes in place when working with the newer organization under `app/`.
- `config.php` is the local runtime entry point; shared bootstrap code in `app/bootstrap/app_config.php` establishes the MySQLi connection, session, security headers, CSRF token, and common helpers. Customer and admin markup is shared through the view templates in `app/views/`; root-level include wrappers preserve legacy references.
- Reusable inventory and order parsing logic lives in `app/helpers/` (with root compatibility files). `inventory_functions.php` deducts recipe and size-specific packaging inventory; `order_ingredients_helper.php` calculates the ingredients/packaging shown to admins. Product recipes and option-specific packaging use separate database relationships.
- The storefront flow spans catalog/menu and product pages, `cart.php`, `checkout.php`, and `orders.php`; authentication, profile, and wallet/top-up pages use the same session and database. Admin page controllers and small AJAX/badge endpoints manage products, orders, users, inventory, and top-ups. Notification integrations are in `app/services/`.
- Browser code is plain files in `Js/` and styles in `Css/`; `manifest.json` and `service-worker.js` provide the PWA behavior. There is no frontend bundler.
- Database structure is represented by `database/schema.sql`; changes for existing installations belong in explicit reviewed migrations under `database/migrations/`. The ignored `database/database.sql` is a private local seed and must not be committed or published; local database dumps are not application inputs.

## Conventions that affect behavior

- Use the shared `$conn` MySQLi connection and prepared statements for values from requests or sessions. Use `escapeOutput()` for rendered values, retain CSRF validation on state-changing requests, and keep request handling/redirects before including a layout that emits HTML.
- Guest carts are keyed by a random `guest_cart_id` in the PHP session and stored using the `guest_` prefix. Keep that identity consistent across catalog, product, cart, and checkout flows; do not make authentication a prerequisite for guest cart operations.
- Order products are serialized in `orders.total_products` with `||` between items and the `Product Name (quantity x size)` shape. Optional `[Preference: ...]` and `[Extras: ...]` annotations may follow an item. Inventory deduction and admin/customer order displays parse this string, so changes to its format must update all related readers and writers together.
- Product options have their own prices and stock. Product-wide recipe ingredients and option-specific packaging deductions are distinct; preserve their separate semantics when editing product, checkout, or inventory behavior.
- Use `app/` for shared bootstrap, helper, service, and view code, but preserve root compatibility wrappers and controller URLs until all callers have been deliberately migrated.
