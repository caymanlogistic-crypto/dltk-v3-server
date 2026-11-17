# Server copy of DLTK V3 vitrine

PHP-FPM public root: app/local-admin/public. Requests route through public/index.php; Nginx serves assets directly. Only the working DB is used. Runtime config is in a private [workspace] INI file selected by DLTK_CONFIG_FILE. Keep config/runtime.ini, data exports, passwords and backups out of Git. UI components/styles and current keyboard/editing/live-presence behavior are preserved from the local project.

Runtime contract: [workspace] INI, APP_URL/WS_URL/DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASSWORD/PRIVATE_DIR. DLTK_CONFIG_FILE selects the private file. APP_URL is a root origin (a separate virtual host/port), not a subdirectory. Preserve the incoming Host header at Nginx. Cookie name is dltk_v3_server and Secure is enabled for HTTPS APP_URL. /api/health returns dltk-v3-server-v1 and checks only the working DB. Unknown legacy source/admin routes return404.

Portable QA: all PHP and JavaScript syntax checks,66 format checks and9 HTTP integration checks passed locally using a dedicated temporary server process. The latter exercised health, distinct cookie, account login,6000-row grid,5 tables/6 roles, configured WebSocket URL, Host/Origin rejection and removed legacy route. No business values were written. Actual Linux deployment/performance remain separate acceptance steps.
