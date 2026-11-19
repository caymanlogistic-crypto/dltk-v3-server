"""Exercise the server copy against an isolated, synthetic MySQL workspace."""

from contextlib import ExitStack, closing
import argparse
import ast
import hashlib
import json
import os
from pathlib import Path
import secrets
import shutil
import socket
import subprocess
import sys
import tempfile
import time
from urllib.error import URLError
from urllib.request import ProxyHandler, build_opener

import pymysql

ROOT = Path(__file__).resolve().parents[1]
SCHEMA = "dltk_v3_qa"
BIND = "127.0.0.1"
NO_WINDOW = getattr(subprocess, "CREATE_NO_WINDOW", 0)


def reserve_ports():
    with ExitStack() as stack:
        listeners = [stack.enter_context(socket.socket()) for _ in range(3)]
        for listener in listeners:
            listener.bind((BIND, 0))
        return [listener.getsockname()[1] for listener in listeners]


def stop(process):
    if process.poll() is None:
        process.terminate()
        try:
            process.wait(timeout=10)
        except subprocess.TimeoutExpired:
            process.kill()
            process.wait()


def start(stack, argv, log, env=None):
    stream = stack.enter_context(log.open("wb"))
    process = subprocess.Popen(
        argv, cwd=ROOT, env=env, stdout=stream, stderr=subprocess.STDOUT,
        creationflags=NO_WINDOW,
    )
    stack.callback(stop, process)
    return process


def wait_for(process, probe, label):
    deadline = time.monotonic() + 45
    while process.poll() is None and time.monotonic() < deadline:
        try:
            return probe()
        except (pymysql.err.OperationalError, URLError, TimeoutError):
            time.sleep(0.25)
    raise RuntimeError(f"{label} did not become ready")


def seed(connection, schema_source, app_password, demo_password):
    # Read literal declarations only. The original setup also imports the old mirror.
    tree = ast.parse(schema_source.read_text(encoding="utf-8-sig"))
    declarations = {
        node.targets[0].id: ast.literal_eval(node.value)
        for node in tree.body
        if isinstance(node, ast.Assign) and isinstance(node.targets[0], ast.Name)
        and node.targets[0].id in {"DDL", "ROLES"}
    }
    fields = list(zip("ABCDEF", ("Статус", "Название", "Перевозчик", "Дата", "Время", "Комментарий")))
    columns = json.dumps([{"letter": key, "label": label} for key, label in fields])
    with connection.cursor() as cursor:
        cursor.execute(f"CREATE DATABASE {SCHEMA} CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci")
        cursor.execute(f"USE {SCHEMA}")
        for ddl in declarations["DDL"]:
            cursor.execute(ddl)
        cursor.executemany("INSERT INTO workspace_meta VALUES(%s,%s)", [
            ("marker", json.dumps("dltk-workspace-v1")),
            ("access_mode", json.dumps("open")), ("policy_version", "1"),
        ])
        for code, name in declarations["ROLES"]:
            cursor.execute("INSERT INTO roles(code,name) VALUES(%s,%s)", (code, name))
            role = cursor.lastrowid
            salt = secrets.token_hex(16)
            digest = hashlib.pbkdf2_hmac("sha256", demo_password.encode(), salt.encode(), 210000).hex()
            cursor.execute(
                "INSERT INTO users(login,name,password_hash) VALUES(%s,%s,%s)",
                (code, f"QA — {name}", f"pbkdf2_sha256$210000${salt}${digest}"),
            )
            cursor.execute("INSERT INTO user_roles VALUES(%s,%s)", (cursor.lastrowid, role))
        for number in range(1, 6):
            demo = number != 1
            slug = f"table-{number}" if demo else "vyvoz"
            cursor.execute(
                "INSERT INTO table_definitions(slug,title,columns_json,carrier_column,is_demo)"
                " VALUES(%s,%s,%s,'C',%s)",
                (slug, f"QA — {slug}", columns, demo),
            )
            table = cursor.lastrowid
            cursor.execute("INSERT INTO catalogs(title,scope,kind) VALUES(%s,'local','status')", (f"Статусы {slug}",))
            catalog = cursor.lastrowid
            cursor.execute("INSERT INTO catalog_bindings VALUES(%s,%s,'A')", (catalog, table))
            cursor.executemany(
                "INSERT INTO catalog_entries(catalog_id,name,normalized,color) VALUES(%s,%s,%s,%s)",
                [(catalog, "В пути", "в пути", "#CFE9E4"), (catalog, "Сдано", "сдано", "#DCEAD4")],
            )
            initial = json.dumps(dict(zip("ABCDEF", ("В пути", "QA row", "QA carrier", "19.11.2026", "08:00", "QA"))))
            cursor.execute(
                "INSERT INTO records(table_id,row_num,values_json,baseline_json) VALUES(%s,2,%s,%s)",
                (table, initial, initial),
            )
        cursor.execute("CREATE USER 'dltk_qa'@'127.0.0.1' IDENTIFIED BY %s", (app_password,))
        for ddl in declarations["DDL"]:
            table = ddl.split()[5]
            grants = "SELECT,INSERT" if table in {"change_history", "outbox_events"} else "SELECT,INSERT,UPDATE,DELETE"
            cursor.execute(f"GRANT {grants} ON {SCHEMA}.{table} TO 'dltk_qa'@'127.0.0.1'")


def run(args):
    mysql = Path(args.mysqld).resolve(strict=True)
    sql_port, http_port, ws_port = reserve_ports()
    base_url = f"http://{BIND}:{http_port}"
    root_password, app_password, demo_password = (secrets.token_urlsafe(24) for _ in range(3))
    with tempfile.TemporaryDirectory(prefix="dltk-v3-qa-") as folder, ExitStack() as stack:
        private = Path(folder).resolve()
        data = private / "mysql"
        sql_args = [
            str(mysql), "--no-defaults", f"--basedir={mysql.parent.parent}", f"--datadir={data}",
            "--mysqlx=OFF", "--skip-log-bin", "--secure-file-priv=NULL",
        ]
        with (private / "mysql-init.log").open("wb") as log:
            subprocess.run(
                [*sql_args, "--initialize-insecure", "--console"], check=True,
                stdout=log, stderr=subprocess.STDOUT, creationflags=NO_WINDOW, timeout=90,
            )
        init_sql = private / "mysql-first-start.sql"
        init_sql.write_text(f"ALTER USER 'root'@'localhost' IDENTIFIED BY '{root_password}';\n", encoding="ascii")
        # Windows mysqld otherwise spawns a child that outlives the launcher PID.
        if os.name == "nt":
            sql_args.append("--no-monitor")
        mysql_process = start(stack, [
            *sql_args, f"--port={sql_port}", f"--bind-address={BIND}", f"--init-file={init_sql}", "--console",
        ], private / "mysql.log")
        connection = wait_for(mysql_process, lambda: pymysql.connect(
            host=BIND, port=sql_port, user="root", password=root_password, autocommit=True, connect_timeout=2,
        ), "Temporary MySQL")
        with closing(connection):
            with connection.cursor() as cursor:
                cursor.execute("SELECT @@datadir, @@port")
                actual_dir, actual_port = cursor.fetchone()
                if Path(actual_dir).resolve() != data or actual_port != sql_port:
                    raise RuntimeError("Unexpected MySQL instance")
            seed(connection, args.schema_source, app_password, demo_password)
        init_sql.unlink()

        sessions = private / "sessions"
        sessions.mkdir()
        config = private / "runtime.ini"
        config.write_text("\n".join(["[workspace]", *(
            f'{key} = "{value}"' for key, value in {
                "APP_URL": base_url, "APP_BASE_PATH": "", "WS_URL": f"ws://{BIND}:{ws_port}",
                "DB_HOST": BIND, "DB_PORT": sql_port, "DB_NAME": SCHEMA, "DB_USER": "dltk_qa",
                "DB_PASSWORD": app_password, "PRIVATE_DIR": private.as_posix(),
            }.items()
        )]) + "\n", encoding="utf-8")
        password_file = private / "demo-access.txt"
        password_file.write_text(f"Пароль для демоаккаунтов: {demo_password}\n", encoding="utf-8")
        env = dict(os.environ, DLTK_CONFIG_FILE=str(config), DLTK_WS_BIND=BIND, DLTK_WS_PORT=str(ws_port))
        php_process = start(stack, [
            args.php, "-d", f"session.save_path={sessions}", "-S", f"{BIND}:{http_port}",
            "-t", "app/local-admin/public", "app/local-admin/router.php",
        ], private / "php.log", env)
        opener = build_opener(ProxyHandler({}))
        wait_for(php_process, lambda: opener.open(base_url + "/api/health", timeout=2).close(), "Temporary PHP")
        ws_process = start(stack, [sys.executable, "tools/workspace_realtime.py"], private / "ws.log", env)
        wait_for(ws_process, lambda: socket.create_connection((BIND, ws_port), timeout=2).close(), "Temporary WebSocket")

        print(f"Isolated workspace: {base_url}/login", flush=True)
        if args.serve:
            print(f"Temporary demo access: {password_file}\nPress Enter or Ctrl+C to stop.", flush=True)
            try:
                input()
            except EOFError:
                while php_process.poll() is None and ws_process.poll() is None:
                    time.sleep(1)
        else:
            subprocess.run([
                sys.executable, "tests/server_e2e.py", "--no-proxy", "--base-url", base_url,
                "--password-file", str(password_file),
            ], cwd=ROOT, check=True, timeout=75)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--schema-source", required=True, type=Path, help="Path to local tools/workspace_setup.py")
    parser.add_argument("--mysqld", default=shutil.which("mysqld") or "mysqld.exe")
    parser.add_argument("--php", default=shutil.which("php") or "php")
    parser.add_argument("--serve", action="store_true", help="Keep the fixture open for manual browser QA")
    args = parser.parse_args()
    try:
        run(args)
    except KeyboardInterrupt:
        raise SystemExit(0 if args.serve else 130) from None
