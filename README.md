# Six Origins

## Run locally with Docker Compose

Docker Desktop (or Docker Engine with the Compose plugin) is required.

1. Copy `.env.example` to `.env` and change both database passwords (in PowerShell: `Copy-Item .env.example .env`).
2. For XAMPP/local PHP (not Docker), create the ignored runtime entrypoint from its safe sample: `Copy-Item config.sample.php config.php`.
3. From the repository root, run:

   ```sh
   docker compose up --build -d
   ```

4. Open `http://localhost:8080` (or the port configured by `APP_PORT`).

The database connection uses `DB_HOST`, `DB_USER`, `DB_PASSWORD`, and `DB_NAME`. Compose sets these for the web container. Configure the same variables in Apache when running PHP directly under XAMPP.

On first initialization of an empty Docker database volume, Compose imports the single local SQL file, `database/database.sql`, which contains the schema, audit triggers, and the existing Head Admin account. This file is ignored by Git because it contains the private admin email and password hash. Keep it in your local project folder and never commit or publish it; restore it from a secure backup before initializing Compose from a fresh checkout. To apply schema changes to an existing database, use an explicit reviewed migration; the container init script does not rerun for an existing volume. The ignored root-level `shop_db.sql` and `finalshopdatabase.sql` are local dumps and are not used by Compose or production.

Docker persists sessions, private top-up proofs, private verification documents, and profile/product uploads under `/data`. The entrypoint links the public image folders to the durable volume while leaving bundled images untouched. Security events are written to `logs/security.log` in the separate `app_logs` volume. `docker compose down` stops and removes the containers but keeps the data. `docker compose down -v` also deletes the volumes and all stored application/database data.

The old `auto_tasks.php` endpoint and the unused waste-management feature have been removed. Preparing-order transitions and expiry automation are not run automatically; use the admin order workflows for order status changes.

The application bootstrap, reusable helpers, notification services, and shared customer/admin layout templates live under `app/bootstrap/`, `app/helpers/`, `app/services/`, and `app/views/`. Root-level compatibility files keep existing includes working; keep them in place when adding to this organization until all legacy references are deliberately migrated. Page controllers and AJAX endpoints remain at the root because their existing public URLs are part of the application interface.

### Install Six Origins on a phone

The storefront is installable as a Progressive Web App (PWA). Open its HTTPS URL on Android in Chrome and choose **Install app** or **Add to Home screen** from the browser menu. On iPhone/iPad, open the site in Safari, tap **Share**, then **Add to Home Screen**. The installed app uses the Six Origins artwork from `images/SixOriginspic.jpg` for its app icon and shows a brief animated coffee-themed launch screen. Browser-based installs require HTTPS; `localhost` is also allowed for local testing. Page content still requires an internet connection.

The admin chatbot expects Ollama at `OLLAMA_URL`; by default, the container connects to Ollama on the Docker host at `host.docker.internal:11434`. Set `OLLAMA_URL` in `.env` if Ollama is elsewhere. If the Ollama endpoint is protected with HTTP Basic Auth, set both `OLLAMA_BASIC_AUTH_USERNAME` and `OLLAMA_BASIC_AUTH_PASSWORD`; leave both empty for an unprotected local endpoint. Email notifications use Resend: set `RESEND_API_KEY`, `MAIL_FROM_EMAIL` to an address on a verified Resend domain, and `MAIL_FROM_NAME`. SMS notifications require their respective credentials in `.env`. Never put production secrets or database dumps in Git.

Order status changes, including cancellations and the admin "Done Preparing" action, email the customer. Editing a product emails only registered customers with a previous order containing that product; guest orders are not emailed.

Products can have a separate whole-peso price and stock quantity for each size. Set both on every size row when creating a product; customer catalog, detail, and cart flows use the selected size's saved price.

### Customize branding and theme

The Head Admin can open **Site Customization** from the admin profile menu to change the logo image and text, system colors, homepage background video, and loading animation. Only the configured Head Admin account can save these settings. Supported logo images are JPG, PNG, and WebP (5 MB max); homepage backgrounds support MP4 or WebM video (50 MB max). The uploaded media is stored with execution disabled and persisted under `/data/site_customization` in Docker.

For an existing database or one initialized from the private `database/database.sql` seed used by Docker/production, back it up and apply `database/migrations/20261109_site_customization.sql` before deploying this feature:

```sh
mysql --host="$DB_HOST" --user="$DB_USER" --password "$DB_NAME" < database/migrations/20261109_site_customization.sql
```

Fresh databases initialized from `database/schema.sql` already include the customization table and do not need this migration. For XAMPP, ensure `images/site_customization` is writable by the PHP/Apache process and set `upload_max_filesize=52M` and `post_max_size=53M` in `php.ini` so the Head Admin can upload video files.

The Head Admin can also set separate login, customer-registration, and admin-registration pictures (JPG, PNG, or WebP, 5 MB max), choose a loading style, and edit the login page-load and sign-in progress messages. Loading screens use the configured logo, brand name, and system colors. If the original customization migration was already applied to an existing database, apply `database/migrations/20261109_site_customization_experience.sql` as well; fresh schema imports already include these fields.

The featured homepage section image and shared customer/admin chatbot icon/colors are also customizable. The featured section background, text, and buttons follow the system color palette. On existing databases, apply `20261109_site_customization_chatbots.sql` after the initial customization table and, when needed, the experience migration. Fresh schema imports already include these settings.

The Head Admin can also change the About page background and featured cafe/product images, and edit the existing team members' names, roles, bios, and profile pictures. Existing databases must apply `database/migrations/20261109_site_customization_about_team.sql` after creating the site customization table and applying the earlier site customization migrations:

```sh
mysql --host="$DB_HOST" --user="$DB_USER" --password "$DB_NAME" < database/migrations/20261109_site_customization_about_team.sql
```

Fresh databases initialized from the current `database/schema.sql` already include these fields. The saved system palette is also applied to customer order history, contact, shop/catalog, checkout, profile, and inventory screens.

The Head Admin can customize customer sign-up and sign-in verification screens, their related code emails, and new-device approval emails. Each flow has its own labeled section in Site Customization. Verification screens and emails use the existing system color palette: email backgrounds, cards, headers, headings, message text, and footers follow the configured colors. No verification background image or database migration is needed. Code expiry, attempt limits, and other security behavior remain enforced by the application.

The order-card header text color can be customized independently from the general system text and navbar colors; it applies to both customer order history and admin order management. Back up existing databases, then apply `database/migrations/20261109_site_customization_order_header_text.sql` after the site customization table exists:

```sh
mysql --host="$DB_HOST" --user="$DB_USER" --password "$DB_NAME" < database/migrations/20261109_site_customization_order_header_text.sql
```

Fresh databases initialized from the current `database/schema.sql` already include this setting.

The Head Admin can also set separate hero/banner images (not full-page backgrounds) for the Shop, Contact, Customer Orders, Search, Cart, Customer Profile, and Wallet Top-up pages. The navbar hover background, text, and border colors are independently customizable. Back up existing databases and apply `database/migrations/20261109_site_customization_page_backgrounds.sql` after the order-header text migration:

```sh
mysql --host="$DB_HOST" --user="$DB_USER" --password "$DB_NAME" < database/migrations/20261109_site_customization_page_backgrounds.sql
```

Page banner images accept JPG, PNG, or WebP up to 5 MB each. Each page can independently restore its default banner. Fresh databases initialized from the current `database/schema.sql` already include these fields.

Existing databases must also add the separate navbar hover text and border colors. Back up the database, then apply `database/migrations/20261109_site_customization_navbar_hover_colors.sql` after the page backgrounds migration:

```sh
mysql --host="$DB_HOST" --user="$DB_USER" --password "$DB_NAME" < database/migrations/20261109_site_customization_navbar_hover_colors.sql
```

The Head Admin can edit the homepage and About-page copy, the customer page hero headings and descriptions, shop quick facts, contact information cards, top-up payment instructions, and footer copy. The About-page impact and Quick Facts images are also replaceable. Available button labels can be edited while their links and actions stay fixed; the homepage hero and specified About call-to-action buttons stay unchanged. The shop Quick Facts “Join Community” button and footer newsletter have been removed. Back up existing databases and apply this migration after the other site customization migrations:

```sh
mysql --host="$DB_HOST" --user="$DB_USER" --password "$DB_NAME" < database/migrations/20261111_site_customization_text_content.sql
```

Fresh databases initialized from the current `database/schema.sql` already include these fields.

### Automatic vouchers and customer rewards

The Head Admin can manage welcome vouchers, completed-order spend/item-count offers, the points exchange rates, and the 7-day daily reward cycle from **Rewards & Vouchers** in the admin profile menu. Customers use **Rewards & Points** in their profile menu to claim daily rewards, see available vouchers, and exchange points for wallet balance or a discount voucher. The reward pages use the configured system color palette.

For an existing database or one initialized from the private database seed, back it up and apply `database/migrations/20261110_loyalty_rewards.sql` before deploying these features:

```sh
mysql --host="$DB_HOST" --user="$DB_USER" --password "$DB_NAME" < database/migrations/20261110_loyalty_rewards.sql
```

Fresh databases initialized from the current `database/schema.sql` already include the reward tables and an initial seven-day cycle. Customer registration now requires email verification; configure `RESEND_API_KEY`, `MAIL_FROM_EMAIL`, and `MAIL_FROM_NAME` for the verification code to be delivered. A welcome voucher is granted after verification. Order-based campaigns use one order's product subtotal or item quantity and are granted once per customer when an admin marks a qualifying order **Completed**; when multiple tiers for the same condition qualify, only the highest tier is granted. Cancelling an order revokes its unused campaign vouchers; already-redeemed vouchers are not clawed back. Daily rewards follow calendar dates, so missed days are skipped. The next seven-day cycle must be published by the Head Admin.

Automatic percentage voucher offers can optionally set a maximum discount cap. The cap applies to the whole product subtotal for one redemption; blank or zero means unlimited. Existing databases must apply `database/migrations/20261113_voucher_maximum_discount_cap.sql` after the loyalty rewards migration:

```sh
mysql --host="$DB_HOST" --user="$DB_USER" --password "$DB_NAME" < database/migrations/20261113_voucher_maximum_discount_cap.sql
```

Fresh databases initialized from the current `database/schema.sql` already include the cap columns.

The wallet-points conversion can use fractional point rates (for example, 2.50 points per ₱1, equivalent to 5 points for ₱2). For an existing database, back it up and apply this migration after the loyalty rewards migration and before deploying code that changes the wallet conversion:

```sh
mysql --host="$DB_HOST" --user="$DB_USER" --password "$DB_NAME" < database/migrations/20261110_loyalty_fractional_wallet_rate.sql
```

Wallet credits from points are rounded to the nearest cent; customers may redeem whole-point amounts.

The Head Admin can also control whether Cash on Delivery, wallet-paid delivery, and wallet-paid store pickup are offered. Existing databases must apply the checkout-method migration after the loyalty rewards migration:

```sh
mysql --host="$DB_HOST" --user="$DB_USER" --password "$DB_NAME" < database/migrations/20261110_checkout_payment_methods.sql
```

Fresh databases initialized from the current `database/schema.sql` already include the payment settings. The admin chatbot can create products with up to 10 size/price/stock variants, create automatic signup/order voucher campaigns, and update points redemption settings. Voucher campaigns and points settings are restricted to the Head Admin and require explicit chatbot confirmation before saving.

To manage sizes later, open a product's Edit dialog to add new product-specific options with their own names, prices, and starting stock, or hide/unhide an existing option. Names are free-form (for example, “Small,” “1 Slice,” or “Whole Cake”); they do not have to be standard cup sizes. Hidden options are no longer offered to customers, but their records and order history are retained. Under Manage Ingredients, recipe ingredients have their own product-wide form and are deducted for every option. Use the separate **Add Packaging for a Product Option** button to manually select an inventory item, the matching customer option, and the per-sale quantity. Items from any inventory category can be selected, including Consumable. Only orders for that selected option deduct the assigned amount, so a Small Cup mapping does not affect Medium or Large. Leave options unmapped when no additional inventory deduction is needed.

Existing databases must apply the size-packaging and inventory-category migration before using these features. Back up the database first, then run the migration against the application database:

```sh
mysql --host="$DB_HOST" --user="$DB_USER" --password "$DB_NAME" < database/migrations/20261007_size_packaging_inventory.sql
```

Enter the password at the prompt. Fresh databases should be initialized from the current `database/schema.sql` instead of running this migration.

Existing databases should also apply `database/migrations/20261007_cart_size_length.sql` to allow the cart to store the full product option labels supported by `product_sizes`. Back up the database first, then run:

```sh
mysql --host="$DB_HOST" --user="$DB_USER" --password "$DB_NAME" < database/migrations/20261007_cart_size_length.sql
```

Enter the password at the prompt. Fresh databases initialized from the current `database/schema.sql` already have the matching cart column size.

To inspect container output, run `docker compose logs -f web db`.

## Lint JavaScript

Install Node.js and npm, then run `npm install` once to install ESLint and create the npm lockfile. Run `npm run lint` to lint the browser scripts in `Js/`.

## Deploy to Railway

The Docker image reads Railway's `PORT` variable at startup and configures Apache to listen on that port. Deploy the repository as a Dockerfile-based service; Railway will build the root `Dockerfile` automatically.

The Compose MariaDB container is for local development only. In Railway, attach a MySQL-compatible database service or use an external MariaDB/MySQL server, then set these variables on the web service using that database's connection details:

- `DB_HOST`
- `DB_NAME`
- `DB_USER`
- `DB_PASSWORD`

Set `APP_ENV=production`, `APP_DEBUG=false`, and `APP_BASE_URL` to the public HTTPS URL of the Railway service (or custom domain). The service requires `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD`; production also requires `APP_BASE_URL` and `TOPUP_PROOF_DIR`. The local `shop_db.sql` snapshot is ignored by Git and excluded from the Docker image. Do not use it to initialize production.

### Initialize the Railway MySQL 8 database

For a new, empty Railway MySQL 8 database, import the single local file `database/database.sql`. It includes the schema, triggers, and your existing Head Admin account:

```sh
mysql --host="$DB_HOST" --user="$DB_USER" --password "$DB_NAME" < database/database.sql
```

Enter the database password at the prompt. This import is for a new empty database, not an existing database. The file contains your Head Admin login email and existing password hash, so transfer/import it securely and never commit or publish it. The dump-derived local files `shop_db.sql` and `finalshopdatabase.sql` are not deployment inputs and must stay out of Git.

For a fresh database that should contain only the database structure, `database/schema.sql` is a safe DDL-only export of the same tables, indexes, foreign keys, and audit triggers. It contains no administrator account or application data. Import it only into an empty database; it is not a migration for an existing database:

```sh
mysql --host="$DB_HOST" --user="$DB_USER" --password "$DB_NAME" < database/schema.sql
```

Enter the database password at the prompt. Add an administrator through your trusted, private provisioning process; do not use or publish the private seed from `database/database.sql`.

Configure `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `APP_BASE_URL`, `APP_ENV=production`, `APP_DEBUG=false`, `TOPUP_PROOF_DIR=/data/topup_proofs`, and `VERIFICATION_UPLOAD_DIR=/data/verification_uploads` in Railway. Head Admin activity access is matched against `HEAD_ADMIN_EMAIL` (defaults to the seeded Head Admin email `shammahpanot@gmail.com`); set it to the exact email address of the Head Admin account in Railway if that account uses a different address. `HEAD_ADMIN_ID` remains as a fallback if no Head Admin email is present in the admin session. `.env.example` documents local Compose settings and optional integrations (`OLLAMA_URL`, Resend (`RESEND_API_KEY` and `MAIL_FROM_*`), and `TEXTBEE_*`); do not use its local database passwords for Railway. Attach a persistent Railway Volume at `/data`.

Set `TOPUP_PROOF_DIR=/data/topup_proofs` and attach a persistent Railway Volume at `/data`. The entrypoint keeps sessions at `/data/sessions`, proofs at `/data/topup_proofs`, verification documents at `/data/verification_uploads`, and public user-uploaded images at `/data/user_uploads` and `/data/admin_uploads`. Copy existing uploads and proofs into those paths before switching traffic. Top-up proofs and verification documents are served only to signed-in admins.

Before deploying the admin activity viewer to an existing database, apply `database/migrations/20261010_admin_activity_audit.sql`. The activity log keeps records until an administrator removes them directly from the database; the Head Admin viewer supports filtering by admin, action, and date range.

To enable sign-in approval when an account is already signed in on another device, back up the Railway database and apply `database/migrations/20261020_login_session_approval.sql` after initializing it (including when importing `database/database.sql`) and before deploying this version:

```sh
mysql --host="$DB_HOST" --user="$DB_USER" --password "$DB_NAME" < database/migrations/20261020_login_session_approval.sql
```

The existing device can approve or reject the request; unanswered requests expire after 60 seconds. A successful approval signs out the existing device. Fresh databases initialized from `database/schema.sql` already include the required table and column.

To enable email-code confirmation as an alternative to approval on the existing device, back up the database and apply `database/migrations/20261021_login_session_email_approval.sql` after the login-session approval migration and before deploying this version:

```sh
mysql --host="$DB_HOST" --user="$DB_USER" --password "$DB_NAME" < database/migrations/20261021_login_session_email_approval.sql
```

The user must first pass the account password and any configured two-factor check. The emailed code expires after 10 minutes; successful verification signs out the existing device. Fresh databases initialized from the current `database/schema.sql` already include the required columns.

For the 10-minute inactivity logout on an existing database or one initialized from the private `database/database.sql` seed, back up the database and apply `database/migrations/20261031_session_inactivity_timeout.sql` after the login-session approval migration and before deploying this version:

```sh
mysql --host="$DB_HOST" --user="$DB_USER" --password "$DB_NAME" < database/migrations/20261031_session_inactivity_timeout.sql
```

The inactivity timer signs out users, admins, and delivery riders after 10 minutes without interaction. Fresh databases initialized from `database/schema.sql` already include the required column.

To enable the admin-managed Special Product of the Week announcement on an existing database or one initialized from the private seed, back up the database and apply `database/migrations/20261031_weekly_special.sql` before deploying this version:

```sh
mysql --host="$DB_HOST" --user="$DB_USER" --password "$DB_NAME" < database/migrations/20261031_weekly_special.sql
```

Admins can enable multiple products at the same time and add a separate 500-character announcement description for each from Product Management. Fresh databases initialized from `database/schema.sql` already include the required table.

To upgrade an installation that already applied the single-special migration, back up the database and apply `database/migrations/20261031_weekly_special_products.sql`. It preserves the currently selected special and its description, then allows multiple weekly specials.

This file-based session setup assumes a single application replica. Before scaling the web service to multiple replicas, move sessions to a shared session store and media to shared object storage.

Set optional `OLLAMA_URL`, `OLLAMA_BASIC_AUTH_USERNAME`, `OLLAMA_BASIC_AUTH_PASSWORD`, `RESEND_API_KEY`, `MAIL_FROM_EMAIL`, `MAIL_FROM_NAME`, `TEXTBEE_API_KEY`, and `TEXTBEE_DEVICE_ID` variables in Railway for integrations you use. For an ngrok tunnel protected with HTTP Basic Auth, set `OLLAMA_URL` to the tunnel's HTTPS URL ending in `/api/chat`, and set both Basic Auth variables to the credentials configured on that tunnel. Do not expose an unauthenticated Ollama API publicly. The laptop running Ollama and ngrok must stay on and connected for the tunnel to work. Verify the sender domain with Resend before using its address. Rotate any credentials that were ever committed or otherwise exposed before deployment. Removing a key from current files does not revoke it or remove it from Git history; revoke/rotate it in its provider and consider repository history cleanup if this repository was shared publicly.
