# Six Origins

## Run locally with Docker Compose

Docker Desktop (or Docker Engine with the Compose plugin) is required.

1. Copy `.env.example` to `.env` and change both database passwords (in PowerShell: `Copy-Item .env.example .env`).
2. For XAMPP/local PHP (not Docker), create the ignored runtime entrypoint from its safe sample: `Copy-Item config.sample.php config.php`.
3. Put your database dump at the repository root as `shop_db.sql`. Database dumps are intentionally excluded from version control and from the application image.
4. From the repository root, run:

   ```sh
   docker compose up --build -d
   ```

5. Open `http://localhost:8080` (or the port configured by `APP_PORT`).

The database connection uses `DB_HOST`, `DB_USER`, `DB_PASSWORD`, and `DB_NAME`. Compose sets these for the web container. Configure the same variables in Apache when running PHP directly under XAMPP.

The database container imports `shop_db.sql` only when its data volume is first created. That ignored local snapshot is for local development only; it contains personal/test data and does not include the current application schema. For example, the snapshot is missing login lockout fields, order timing fields, inventory/recipe tables, and admin activity tables used by current code. Do not use it to initialize production. Before deployment, provision and migrate a production database from a reviewed schema/data source that includes all tables and columns used by the current application. Back up the database before applying any migration.

Docker persists sessions, private top-up proofs, private verification documents, and profile/product uploads under `/data`. The entrypoint links the public image folders to the durable volume while leaving bundled images untouched. `docker compose down` stops and removes the containers but keeps the data. `docker compose down -v` also deletes the volumes and all stored application/database data.

The old `auto_tasks.php` endpoint has been removed and was not included by any PHP page. Preparing-order transitions and automatic waste/expiry logging formerly implemented there will no longer run automatically; use the admin workflows unless a separately managed scheduler is deliberately added.

The admin chatbot expects Ollama at `OLLAMA_URL`; by default, the container connects to Ollama on the Docker host at `host.docker.internal:11434`. Set `OLLAMA_URL` in `.env` if Ollama is elsewhere. Email and SMS integrations require their respective credentials in `.env`. Never put production secrets or database dumps in Git.

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

Set `APP_ENV=production`, `APP_DEBUG=false`, and `APP_BASE_URL` to the public HTTPS URL of the Railway service (or custom domain). The service refuses requests unless all four database variables are present and the HTTPS base URL and persistent proof-storage path are configured. Import a reviewed, current schema/data set into the database before serving traffic. The local `shop_db.sql` snapshot is ignored by Git and excluded from the Docker image; it is incomplete for the current application and must not be used for production. Verify the schema against login, order, inventory/recipe, admin activity, wallet, and top-up flows before go-live.

Set `TOPUP_PROOF_DIR=/data/topup_proofs` and attach a persistent Railway Volume at `/data`. The entrypoint keeps sessions at `/data/sessions`, proofs at `/data/topup_proofs`, verification documents at `/data/verification_uploads`, and public user-uploaded images at `/data/user_uploads` and `/data/admin_uploads`. Copy existing uploads and proofs into those paths before switching traffic. Top-up proofs and verification documents are served only to signed-in admins.

This file-based session setup assumes a single application replica. Before scaling the web service to multiple replicas, move sessions to a shared session store and media to shared object storage.

Set optional `OLLAMA_URL`, `MAIL_USERNAME`, `MAIL_APP_PASSWORD`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_ENCRYPTION`, `MAIL_FROM_EMAIL`, `MAIL_FROM_NAME`, `TEXTBEE_API_KEY`, and `TEXTBEE_DEVICE_ID` variables in Railway for integrations you use. Rotate any credentials that were ever committed or otherwise exposed before deployment. Removing a key from current files does not revoke it or remove it from Git history; revoke/rotate it in its provider and consider repository history cleanup if this repository was shared publicly.
