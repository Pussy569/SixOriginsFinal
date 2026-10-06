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

Docker persists sessions, private top-up proofs, private verification documents, and profile/product uploads under `/data`. The entrypoint links the public image folders to the durable volume while leaving bundled images untouched. `docker compose down` stops and removes the containers but keeps the data. `docker compose down -v` also deletes the volumes and all stored application/database data.

The old `auto_tasks.php` endpoint and the unused waste-management feature have been removed. Preparing-order transitions and expiry automation are not run automatically; use the admin order workflows for order status changes.

The application bootstrap, reusable helpers, notification services, and shared customer/admin layout templates live under `app/bootstrap/`, `app/helpers/`, `app/services/`, and `app/views/`. Root-level compatibility files keep existing includes working; keep them in place when adding to this organization until all legacy references are deliberately migrated. Page controllers and AJAX endpoints remain at the root because their existing public URLs are part of the application interface.

### Install Six Origins on a phone

The storefront is installable as a Progressive Web App (PWA). Open its HTTPS URL on Android in Chrome and choose **Install app** or **Add to Home screen** from the browser menu. On iPhone/iPad, open the site in Safari, tap **Share**, then **Add to Home Screen**. The installed app uses the Six Origins artwork from `images/SixOriginspic.jpg` for its app icon and shows a brief animated coffee-themed launch screen. Browser-based installs require HTTPS; `localhost` is also allowed for local testing. Page content still requires an internet connection.

The admin chatbot expects Ollama at `OLLAMA_URL`; by default, the container connects to Ollama on the Docker host at `host.docker.internal:11434`. Set `OLLAMA_URL` in `.env` if Ollama is elsewhere. If the Ollama endpoint is protected with HTTP Basic Auth, set both `OLLAMA_BASIC_AUTH_USERNAME` and `OLLAMA_BASIC_AUTH_PASSWORD`; leave both empty for an unprotected local endpoint. Email notifications use Resend: set `RESEND_API_KEY`, `MAIL_FROM_EMAIL` to an address on a verified Resend domain, and `MAIL_FROM_NAME`. SMS notifications require their respective credentials in `.env`. Never put production secrets or database dumps in Git.

Order status changes, including cancellations and the admin "Done Preparing" action, email the customer. Editing a product emails only registered customers with a previous order containing that product; guest orders are not emailed.

Products can have a separate whole-peso price and stock quantity for each size. Set both on every size row when creating a product; customer catalog, detail, and cart flows use the selected size's saved price.

To manage sizes later, open a product's Edit dialog to add new sizes with their own price and starting stock, or hide/unhide an existing size. Hidden sizes are no longer offered to customers, but their records and order history are retained. Under Manage Ingredients, link recipe ingredients at the product level (they apply to every size) and link packaging under “Packaging Used by Size” (only the selected size's packaging is deducted when sold).

Existing databases must apply the size-packaging and inventory-category migration before using these features. Back up the database first, then run the migration against the application database:

```sh
mysql --host="$DB_HOST" --user="$DB_USER" --password "$DB_NAME" < database/migrations/20261007_size_packaging_inventory.sql
```

Enter the password at the prompt. Fresh databases should be initialized from the current `database/schema.sql` instead of running this migration.

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

Configure `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `APP_BASE_URL`, `APP_ENV=production`, `APP_DEBUG=false`, and `TOPUP_PROOF_DIR=/data/topup_proofs` in Railway. `.env.example` documents local Compose settings and optional integrations (`OLLAMA_URL`, Resend (`RESEND_API_KEY` and `MAIL_FROM_*`), and `TEXTBEE_*`); do not use its local database passwords for Railway. Attach a persistent Railway Volume at `/data`.

Set `TOPUP_PROOF_DIR=/data/topup_proofs` and attach a persistent Railway Volume at `/data`. The entrypoint keeps sessions at `/data/sessions`, proofs at `/data/topup_proofs`, verification documents at `/data/verification_uploads`, and public user-uploaded images at `/data/user_uploads` and `/data/admin_uploads`. Copy existing uploads and proofs into those paths before switching traffic. Top-up proofs and verification documents are served only to signed-in admins.

This file-based session setup assumes a single application replica. Before scaling the web service to multiple replicas, move sessions to a shared session store and media to shared object storage.

Set optional `OLLAMA_URL`, `OLLAMA_BASIC_AUTH_USERNAME`, `OLLAMA_BASIC_AUTH_PASSWORD`, `RESEND_API_KEY`, `MAIL_FROM_EMAIL`, `MAIL_FROM_NAME`, `TEXTBEE_API_KEY`, and `TEXTBEE_DEVICE_ID` variables in Railway for integrations you use. For an ngrok tunnel protected with HTTP Basic Auth, set `OLLAMA_URL` to the tunnel's HTTPS URL ending in `/api/chat`, and set both Basic Auth variables to the credentials configured on that tunnel. Do not expose an unauthenticated Ollama API publicly. The laptop running Ollama and ngrok must stay on and connected for the tunnel to work. Verify the sender domain with Resend before using its address. Rotate any credentials that were ever committed or otherwise exposed before deployment. Removing a key from current files does not revoke it or remove it from Git history; revoke/rotate it in its provider and consider repository history cleanup if this repository was shared publicly.
