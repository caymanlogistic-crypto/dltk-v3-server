# Isolated Linux test deployment

This is a separate deployment of DLTK V3. It must never replace the existing 1C-IP v2 application.

## Current choice: shared Apache, PHP-FPM and MySQL

The user explicitly chose the existing common services. `deploy/provision_shared.py` is the first-install adapter for this choice; the standalone/native and Docker variants below are alternatives, not the selected installation. No installer has been executed merely by adding these files.

The shared adapter creates only `/srv/dltk-v3-test`, schema `dltk_v3_test`, account `dltk_v3_app@localhost`, PHP pool `/etc/php/8.4/fpm/pool.d/dltk-v3-test.conf`, Apache site `/etc/apache2/sites-available/dltk-v3-test.conf` plus its enabled symlink, and `dltk-v3-realtime.service`. The new pool runs as its own Linux user and accepts only loopback FCGI on33487, with at most8workers. The WebSocket service accepts loopback33486 and keeps its own resource limits. Apache adds HTTPS8443; existing80/443 sites and pool files remain byte-identical and their hashes enter a private receipt.

MySQL stays on its existing UNIX socket `/var/run/mysqld/mysqld.sock`, with networking disabled (`@@port=0`) and expected datadir `/srv/ip_ya/mysql/data`. Both PHP and Python support `DB_SOCKET`; neither requires enabling TCP. The installer verifies all three server identity values before database creation, refuses an existing target schema/account, imports only the checksum-verified private export, and verifies the workspace marker. Runtime privileges cover only the new schema; audit and outbox tables are append-only. It never changes MySQL authentication/configuration or restarts MySQL.

The standard `php8.4-mbstring` distribution package is installed if needed. During that package transaction a temporary `policy-rc.d` blocks only package-triggered PHP8.4-FPM service actions, delegates all other decisions to any existing policy, and restores that policy in `finally`. It also requests needrestart list-only mode. The new pool is config-tested before an explicit graceful PHP-FPM reload. Apache `proxy_fcgi` and `proxy_wstunnel` are enabled, config-tested and gracefully reloaded. These reloads are the intentional shared-service changes authorized by the user; no old pool or site is replaced. In case the installer process is forcibly killed during the package transaction, inspect `/usr/sbin/.policy-rc.d.dltk-v3-original` and restore the original policy before further package maintenance.

TLS refers to the existing renewed certificate paths supplied by the operator; it does not copy or publish the private key. The installer performs strict certificate-verified HTTPS `/api/health` readiness. A private receipt records exact artifact/SQL checksums, commit, shared-server identity, existing configuration hashes and before/after service state. Demo passwords are randomized only in the new schema and written only to `shared/private/demo-access.txt`.

Run the reviewed installer as root with `--archive`, `--archive-sha`, `--commit`, `--sql`, `--sql-sha`, `--tls-cert`, `--tls-key`. Optional `--mysql-socket` and `--mysql-datadir` must describe the explicitly chosen existing instance. If installation fails, retain the partial new scope for inspection; the first-install command deliberately refuses rerunning over it. There is no automatic schema reset or broad rollback of shared services. No Git or Docker installation on the server is required: source arrives as the checksum-verified artifact of the separate private GitHub repository.

## Actual target: native isolated services (17 November 2026)

The primary agent established authorized SSH access and verified Debian13,4CPU/8GB RAM, MySQL8.4.13, Apache2.4.69 and PHP8.4.27/FPM8.4. Docker, Git and nginx are absent on that host. **The actual native adapter is the target; the Compose section below is an optional candidate and is not a claim of a Docker deployment.** No Docker/global PHP/nginx installation or old service restart is needed for this adapter.

`deploy/native_templates.py` exports the pure function `render_native_files(...)`. It returns8configuration texts and performs no filesystem/network/service mutation. The primary provisioner chooses the exact GitHub commit/artifact, verifies its checksum and places source under this project's root/current; it is responsible for users/directories/secrets, startup and receipts.

Renderer outputs:

- `shared/apache/httpd.conf`: standalone Apache8443 with explicit installed modules, own PID/runtime/logs, gzip, public-root Directory/FallbackResource/index.php, PHP-FCGI127.0.0.1:33487 and WS127.0.0.1:33486. No Include of global Apache config and no change to existing80/443 service.
- `shared/php/fpm.conf` and `shared/php/php.ini`: private FPM pool/config/PID/logs/sessions,8workers. Optional `php_extension` is the isolated extracted mbstring.so; the unit supplies ownshared/php-libs in LD_LIBRARY_PATH, without editing global PHP configuration.
- `shared/mysql.cnf`: onlynewMySQL127.0.0.1:33484, private data/socket/PID/logs, Xplugin disabled to avoid33060conflict,256MiB bufferpool and80connections.
- `units/dltk-v3-{mysql,php,realtime,apache}.service`: own Linux user by default `dltk-v3-test`, bounded memory/CPU/tasks, NoNewPrivileges, private temp, read-only system and write access only to ownshared. Otherproject paths are hidden in these unit namespaces.

The native provisioner must create and assign appropriate ownership to shared/{apache,php,run,logs,sessions,mysql,mysql-files,php-libs,private,tls}. It must initialize only the brand-new datadir, not an existing MySQL instance. Copy the approved existing certificate/key into ownshared/tls before starting the standalone service: the unit intentionally cannot read the old application's directory. No certificate/key value enters Git.

Native runtime.ini uses `[workspace]`, APP_URL=https://89.208.213.14:8443, WS_URL=wss://89.208.213.14:8443/ws, DB_HOST=127.0.0.1, DB_PORT=33484, DB_NAME=dltk_v3_test and privategeneratedcredentials. PRIVATE_DIR points inside ownshared/private. `config/runtime.example.ini` currently describes the optional container paths; use the native host/port/private paths for native services.

Pythonrequirements include `PyMySQL[rsa]` so a fresh MySQL8.4 caching_sha2_password handshake works, plus websockets15.0.1. The service reads DLTK_CONFIG_FILE and accepts only that configuredAPP_URLOrigin. NativeunitWSbind is127.0.0.1:33486. Protocol/presence behavior is identical to the local system.

Local validation: native8template/scope assertions andPythonASTPASS,10realtime/configunitsPASS, optionalComposebase+TLS `docker compose config --quiet`PASS. These checks do not substitute for the primary agent's real native Apache/FPM/MySQL config tests, strictTLSreadiness, data preservation and latency benchmark on the host. Native deployment evidence belongs in docs/HANDOFF.md and the primary receipt.

## Optional container alternative



## Boundaries

- Dedicated root: `/srv/dltk-v3-test` and ownership marker `.dltk-test-project`.
- Source checkout: `/srv/dltk-v3-test/repository`, fetched from the separate private GitHub repository at one exact commit.
- Runtime data/config: `/srv/dltk-v3-test/shared`, outside the Git checkout.
- Docker Compose project: `dltk-v3-test`. Database service `db`, own MySQL8.4 data directory, schema `dltk_v3_test`. No database/FPM/WebSocket ports are published to the host.
- Default HTTP listener: `127.0.0.1:33485`, suitable for an SSH tunnel. Optional TLS listener: port8443, a separate nginx container/vhost, not the host's port443.
- PHP8.3-FPM and Python3.12 WebSocket15.0.1; nginx1.28. No Windows paths or Google integration in the server adapter.
- Root-owned runtimeINI grants PHP/WS group33 read access only. Application config is not copied into images; SQL backups/imports, passwords and TLS private keys never enter Git.

The stack is bounded by service CPU/memory limits. These are initial limits, not a measured capacity guarantee. The preflight refuses deployment below1800MiB available RAM; inspect the existing host workload before a15-user benchmark. It does not install Docker or restart the Docker daemon.

## Prerequisites and initial access

A trusted operator needs Linux shell access with root/sudo, Git, Docker Engine/Compose v2+, Python3, and permission to read this private GitHub repository. Use a dedicated read-only deploy key or an already-authorized SSH agent; no tokens/passwords in Git URLs or command logs. Verify the pinned server host key before connecting. The credentials for the old project's forced-command deployment broker cannot be reused to modify another project.

The initial container candidate was prepared before trusted server access was available. The primary agent later established access and selected the native adapter above. Successful syntax/Compose checks do not mean containers are running on that host; record the actual native host/readiness/commit separately.

## Bootstrap through Git

First retrieve the deployment helpers from the new repository into a temporary bootstrap checkout, select the exact reviewed commit, then run them. Example placeholders below are not commands to mutate the existing ERP:

```sh
git clone git@github.com:OWNER/dltk-v3-server.git /tmp/dltk-v3-bootstrap
git -C /tmp/dltk-v3-bootstrap checkout --detach FULL_COMMIT_SHA
sudo python3 /tmp/dltk-v3-bootstrap/deploy/prepare_config.py --app-url https://89.208.213.14:8443
```

`prepare_config.py` creates only the dedicated root/shared directories and private runtime files. Existing complete config is preserved; partial config or a nonempty unowned root causes a stop. It generates the isolated MySQL root/runtime passwords locally, without printing them. The runtime account is `dltk_app`; PHP and Python share `[workspace]` values in `shared/runtime.ini` through `DLTK_CONFIG_FILE=/run/dltk/runtime.ini`.

Keep `shared/compose.env` and `shared/db-admin.cnf` root0600; `shared/runtime.ini` root:33 0640. `shared/private` is writable by UID33 inside the app container. No SQL data/user password is placed in README or receipts.

For public testing install a valid trusted certificate chain and matching key as `shared/tls/fullchain.pem` and `shared/tls/privkey.pem`. The key stays root-readable only. The TLS override publishes8443; do not alter the other application's443 configuration. Do not bypass browser certificate warnings or use `curl -k` as a claimed readiness test. If trusted TLS is not available, use the loopback HTTP configuration/SSH tunnel and configure its exact HTTP origin instead of exposing unencrypted HTTP publicly.

## Data import

The approved local working database snapshot is transferred through the trusted server channel separately from Git. It must contain only this project's tables and no CREATE/DROP DATABASE, USE, user or grant commands. Retain its SHA256 manifest. Store it under `shared/private` outside public/Git paths.

```sh
sudo python3 /tmp/dltk-v3-bootstrap/deploy/deploy.py \
  --repository git@github.com:OWNER/dltk-v3-server.git \
  --revision FULL_COMMIT_SHA --tls \
  --initial-data /srv/dltk-v3-test/shared/private/initial.sql \
  --sha256 APPROVED_DUMP_SHA256
```

The deployer validates the ownership marker and exact Git origin, refuses a dirty checkout, fetches Git and checks out the specified full commit without force-pushing. It builds only its PHP/WS images and starts its own MySQL service. `import_database.py` validates checksum and schema routing and **refuses any nonempty schema**, so rerunning the initial command never overwrites working data. Failed partial imports require inspection, not a blind retry/reset.

After import the runtime SQL account is narrowed to this schema's tables; audit/outbox grants are SELECT+INSERT only. The deployer checks the workspace marker, starts its own stack and verifies `/api/health` with normal TLS verification and marker `dltk-v3-server-v1`. A non-secret receipt with commit, previous commit and URL is written to `shared/deployments` only after readiness passes.

## Later releases and rollback

For a later exact commit omit `--initial-data`/`--sha256`. Existing MySQL data, private files and sessions are separate from source. Deployments use an exclusive project lock. No migration/reset or old project service action is hidden in the deploy command.

Rollback means invoking the same deployer with the previous reviewed commit and **without initial data**, after assessing whether data schema changes are compatible. There is no automatic rollback after a timeout because a failed readiness check and an uncommitted deployment are different states. Never use `docker compose down -v` or delete shared/mysql as a repair step.

Own-stack commands:

```sh
cd /srv/dltk-v3-test/repository
sudo docker compose --project-name dltk-v3-test -f compose.yaml -f deploy/compose.tls.yaml ps
sudo docker compose --project-name dltk-v3-test -f compose.yaml -f deploy/compose.tls.yaml logs --tail=80 php realtime nginx
```

Do not dump compose configuration/environment or arbitrary SQL row contents into a public report. Data backup/restore needs a separate approved procedure and actual restore verification; this initial deployment helper is not a backup scheduler.

## WebSocket/presence behavior

The server copy reads the same private INI as PHP, accepts only the configured APP_URL origin, and binds0.0.0.0:33386 only inside its backend network. nginx `/ws` handles Upgrade. One reusable SQL connection/outbox poller serves all clients; single-use tickets, active/auth_version checks, per-user preferences, durable replay, presence leases and clipboard action events are preserved. Ephemeral editing markers do not write values/history/outbox. Any access_rules currently fail closed for event delivery; full restricted-data policies remain a future feature.

## Evidence required before announcing server success

1. Exact repository URL and commit actually running on the target host.
2. Dedicated container/service names, listeners and DB schema; unchanged old application health.
3. Strict-TLS login, authenticated grid/history/catalog/presence checks, two-user edits and clipboard undo.
4. Real data snapshot row/field/reference counts against its manifest.
5.15 distinct sessions/connections with measured p50/p95 reads/saves/event latency, not just an HTTP200 screenshot. Limit tests to demo rows and restore their values while retaining factual audit history.
6. Record versions, host resource context, errors, limits and the fact that this remains an isolated test, not a production capacity promise.
