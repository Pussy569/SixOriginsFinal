# Repository guidance

## Runtime and commands

- This is a procedural PHP application intended to run under Apache with MySQL/MariaDB (the repository uses XAMPP). The application connects using `DB_HOST`, `DB_USER`, `DB_PASSWORD`, and `DB_NAME`. The one local database import file is `database/database.sql`; it contains the existing Head Admin email and password hash and is ignored by Git. Root SQL dumps are also ignored local-only data. Keep PHP query logic in the page/controller that owns the flow unless explicitly asked to refactor it.
- Install the Composer dependencies from the repository root with `php .\composer.phar install`. The project has no application build script or configured formatter, linter, or automated test runner.
- Check one PHP file's syntax with `php -l .\path\to\file.php`. To syntax-check root-level PHP files in PowerShell, run `Get-ChildItem -Filter *.php | ForEach-Object { php -l $_.FullName }`.

## Architecture

- Each root-level PHP page generally acts as both its request handler and its HTML view. Pages use `config.php` for the MySQLi connection, session, security headers, CSRF token, and shared helpers. Customer-facing pages compose `header.php` and `footer.php`; admin pages use `admin_header.php` and `admin_footer.php`.
- The customer flow spans catalog/menu and product pages, `cart.php`, `checkout.php`, and `orders.php`. Authentication, profile, and wallet/top-up pages share the same session and database. Guest carts are associated with a session-generated `guest_cart_id`; preserve that guest behavior when changing cart ownership or checkout.
- Admin functionality is split across page controllers and small AJAX/badge endpoints. Order and inventory behavior is shared through `inventory_functions.php` and `order_ingredients_helper.php`; product recipes link `products` to inventory ingredients via `product_ingredients`.
- Browser assets are plain files in `Css/` and `Js/`; there is no frontend bundler in the repository.

## Conventions that affect behavior

- Use the shared MySQLi connection (`$conn`) and prepared statements with bound parameters for queries involving request or session values. `config.php` provides `escapeOutput()`, `sanitizeInput()`, and `validateCSRFToken()`; escape values when rendering HTML and retain CSRF checks for state-changing requests.
- Order line items are serialized with `||` between products and use the `Product Name (quantity x size)` shape. Preference/extra annotations can follow that shape in brackets. The order ingredient helper parses this representation, so keep its delimiter and line format compatible when changing cart, checkout, or order code.
- Customer and admin pages have separate shared layout includes. Keep page-specific request handling before emitting the shared HTML layout, especially where redirects or HTTP status codes are required.
