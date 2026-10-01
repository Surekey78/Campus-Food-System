#!/data/data/com.termux/files/usr/bin/bash
# =====================================================================
#  Campus Food System  —  one-command starter for Termux
# =====================================================================
#  Installs what's missing, initialises MariaDB, creates the database
#  with a randomly generated password, then serves the app.
#
#  Quick start:
#      ./termux-start.sh                 # install + run (Ctrl+C to stop)
#      ./termux-start.sh up              # run in the background
#      ./termux-start.sh status          # what's running?
#      ./termux-start.sh down            # stop everything
#
#  Run  ./termux-start.sh --help  for the full list of commands.
#
#  Facts about Termux this script relies on (checked against the
#  termux-packages build scripts):
#    * The `php` package is built with --with-mysqli=mysqlnd and
#      --enable-mbstring, so mysqli works with no extra package.
#    * PHP's compiled-in default socket is $PREFIX/tmp/mysqld.sock,
#      but the MariaDB server creates $PREFIX/var/run/mysqld.sock.
#      Those do NOT match — so this script always connects over TCP
#      on 127.0.0.1 and never relies on `localhost` (socket) lookups.
#    * Termux's mariadb postinst initialises the data directory with
#      --auth-root-authentication-method=normal, so the MySQL root
#      account starts with an EMPTY password (like XAMPP). The app
#      itself uses a separate, password-protected account.
# =====================================================================

set -u
set -o pipefail

# ---------------------------------------------------------------------
#  Locations
# ---------------------------------------------------------------------
SCRIPT_PATH="${BASH_SOURCE[0]:-$0}"
PROJECT_DIR="$(cd "$(dirname "$SCRIPT_PATH")" && pwd)"

# ---------------------------------------------------------------------
#  Which build is this — demo or production?
#
#  tools/make-release.sh ships two zips. The demo one carries
#  database/demo-data.sql and the sample photos; the production one has
#  neither, because a real canteen should not start life with 27 dishes
#  and three accounts whose passwords are printed in the README.
#
#  Everything downstream works either way. This flag just stops the
#  script asking for a file the production build does not contain.
# ---------------------------------------------------------------------
HAS_DEMO_DATA=0
if [ -f "$PROJECT_DIR/database/demo-data.sql" ]; then
    HAS_DEMO_DATA=1
fi

# Termux always exports $PREFIX. If it is missing we guess the standard
# location, but we also check that it really exists before believing it.
DEFAULT_TERMUX_PREFIX="/data/data/com.termux/files/usr"
PREFIX="${PREFIX:-$DEFAULT_TERMUX_PREFIX}"

RUN_DIR="$PROJECT_DIR/.run"
ENV_FILE="$PROJECT_DIR/.env"
LOG_DB="$RUN_DIR/mariadb.log"
LOG_WEB="$RUN_DIR/php-server.log"
PID_DB="$RUN_DIR/mariadb.pid"
PID_WEB="$RUN_DIR/php-server.pid"

DATADIR="$PREFIX/var/lib/mysql"
SOCKET="$PREFIX/var/run/mysqld.sock"

# ---------------------------------------------------------------------
#  Defaults (override with flags, see --help)
# ---------------------------------------------------------------------
WEB_PORT=8080
DB_PORT=3306
BIND_ADDR="127.0.0.1"     # --lan changes this to 0.0.0.0
DB_NAME="campus_food"
DB_USER="campus"
WITH_SEED=$HAS_DEMO_DATA    # demo build seeds itself; production starts empty
FRESH=0
ASSUME_YES=0
ANYWHERE=0
ROOT_PASS="${MYSQL_ROOT_PASSWORD:-}"

# ---------------------------------------------------------------------
#  Pretty output
# ---------------------------------------------------------------------
if [ -t 1 ]; then
    B=$'\033[1m'; DIM=$'\033[2m'; R=$'\033[0m'
    GRN=$'\033[32m'; YEL=$'\033[33m'; RED=$'\033[31m'; CYA=$'\033[36m'
else
    B=''; DIM=''; R=''; GRN=''; YEL=''; RED=''; CYA=''
fi

say()  { printf '%s\n' "$*"; }
step() { printf '\n%s▸ %s%s\n' "$B$CYA" "$*" "$R"; }
ok()   { printf '  %s✓%s %s\n' "$GRN" "$R" "$*"; }
info() { printf '  %s·%s %s\n' "$DIM" "$R" "$*"; }
warn() { printf '  %s!%s %s\n' "$YEL" "$R" "$*"; }
die()  { printf '\n%s✗ %s%s\n\n' "$B$RED" "$*" "$R" >&2; exit 1; }

have() { command -v "$1" >/dev/null 2>&1; }

# Find the first available command from a list of candidates.
find_cmd() {
    local c
    for c in "$@"; do
        if command -v "$c" >/dev/null 2>&1; then command -v "$c"; return 0; fi
        if [ -x "$PREFIX/bin/$c" ]; then printf '%s\n' "$PREFIX/bin/$c"; return 0; fi
    done
    return 1
}

# True when something is listening on the given TCP port.
port_open() {
    (echo > "/dev/tcp/127.0.0.1/$1") >/dev/null 2>&1
}

# Best-effort LAN address, used only for the "open on another device" hint.
lan_ip() {
    local ip
    ip=$(ifconfig 2>/dev/null | awk '/inet[ ]/{print $2}' | sed 's/^addr://' | grep -v '^127\.' | head -1)
    if [ -z "$ip" ]; then
        ip=$(ip -4 addr show 2>/dev/null | awk '/inet[ ]/{print $2}' | cut -d/ -f1 | grep -v '^127\.' | head -1)
    fi
    if [ -z "$ip" ]; then
        ip=$(getprop dhcp.wlan0.ipaddress 2>/dev/null)
    fi
    printf '%s' "${ip:-}"
}

# ---------------------------------------------------------------------
#  Help
# ---------------------------------------------------------------------
usage() {
    cat <<EOF

${B}Campus Food System — Termux starter${R}

${B}USAGE${R}
  ./termux-start.sh [command] [options]

${B}COMMANDS${R}
  run       Install, start MariaDB, set up the database, serve the app.
            This is the default and runs in the foreground (Ctrl+C stops it).
  up        Same, but leave MariaDB and the web server running in the
            background and return to your prompt.
  down      Stop the web server and MariaDB that this script started.
  restart   down, then up.
  status    Show whether the database and web server are running.
  logs      Tail the MariaDB and web server logs.
  db        Open an interactive MariaDB shell on the campus database.
  reset     Drop the database and re-import schema + sample data.
  sql [f]   Run a .sql file (default: schema + seed) against the database.
  cred      Show the generated database credentials in .env.
  help      This message.

${B}OPTIONS${R}
  -p, --port N     Web port (default $WEB_PORT)
      --db-port N  MariaDB port (default $DB_PORT)
      --lan        Also accept connections from other devices on your Wi-Fi
  -y, --yes        Don't ask before installing packages
      --no-seed    Create the schema with no sample menu/orders
      --fresh      Re-import the schema even if the database exists
      --anywhere   Run on a normal Linux box too (uses \$PREFIX paths)
  -h, --help       This message

${B}FIRST RUN${R}
  Demo logins once the sample data is loaded:
      admin@campus.edu / admin123     (canteen admin)
      maria@campus.edu / campus123    (student)

${B}TIP${R}
  Add a shortcut to your shell so you can type \`campus\` from anywhere:
      echo "alias campus='$PROJECT_DIR/termux-start.sh'" >> ~/.bashrc && source ~/.bashrc

EOF
}

# ---------------------------------------------------------------------
#  Argument parsing
# ---------------------------------------------------------------------
COMMAND="run"
POSITIONAL=()
SEEN_COMMAND=0

while [ $# -gt 0 ]; do
    case "$1" in
        # ---- flags ----
        -h|--help|help|usage) usage; exit 0 ;;
        -p|--port)   WEB_PORT="${2:-}"; [ -n "$WEB_PORT" ] || die "--port needs a number"; shift 2 ;;
        --db-port)   DB_PORT="${2:-}";  [ -n "$DB_PORT" ]  || die "--db-port needs a number"; shift 2 ;;
        --lan)       BIND_ADDR="0.0.0.0"; shift ;;
        -y|--yes)    ASSUME_YES=1; shift ;;
        --no-seed)   WITH_SEED=0; shift ;;
        --fresh)     FRESH=1; shift ;;
        --anywhere)  ANYWHERE=1; shift ;;
        --)          shift; POSITIONAL+=("$@"); break ;;

        # ---- unknown flags ----
        -*) die "Unknown option '$1'. Try: ./termux-start.sh --help" ;;

        # ---- first bare word is the command, the rest are file paths ----
        *)
            if [ "$SEEN_COMMAND" -eq 0 ]; then
                COMMAND="$1"
                SEEN_COMMAND=1
            else
                POSITIONAL+=("$1")
            fi
            shift ;;
    esac
done

case "$COMMAND" in
    run|up|down|restart|status|logs|db|reset|sql|cred) ;;
    *) die "Unknown command '$COMMAND'. Try: ./termux-start.sh --help" ;;
esac

# ---------------------------------------------------------------------
#  Environment checks
# ---------------------------------------------------------------------
case "$PREFIX" in
    *com.termux*) IS_TERMUX=1 ;;
    *)            IS_TERMUX=0 ;;
esac

# A guessed prefix for a directory that isn't there is not a Termux box.
if [ "$IS_TERMUX" -eq 1 ] && [ ! -d "$PREFIX/bin" ]; then
    IS_TERMUX=0
fi

if [ "$IS_TERMUX" -eq 0 ] && [ "$ANYWHERE" -eq 0 ]; then
    cat <<EOF

${B}${RED}This doesn't look like Termux.${R}

  ${DIM}\$PREFIX is${R} ${PREFIX:-<unset>}
  ${DIM}it should contain "com.termux" and exist${R}

  If you are on Termux and seeing this, your \$PREFIX is unusual — you can
  bypass the check with --anywhere.

  If you are on a normal Linux box (Debian, Ubuntu, Mint…), skip this
  script entirely: you already have a real package manager.

      sudo apt install php php-mysql mariadb-server
      # then follow the README from "Quick start", option B

EOF
    exit 1
fi

MARIADBD=$(find_cmd mariadbd mysqld)     || MARIADBD=""
MARIADB=$(find_cmd mariadb mysql)        || MARIADB=""
INSTALL_DB=$(find_cmd mariadb-install-db mysql_install_db) || INSTALL_DB=""
PHP_BIN=$(find_cmd php)                  || PHP_BIN=""
PKG=$(find_cmd pkg)                      || PKG=""

install_packages() {
    local missing=()
    [ -x "$PHP_BIN" ]      || missing+=(php)
    [ -n "$MARIADBD" ]     || missing+=(mariadb)

    if [ ${#missing[@]} -eq 0 ]; then
        ok "php and mariadb are already installed"
        return 0
    fi

    if [ -z "$PKG" ]; then
        die "Missing packages (${missing[*]}) and no 'pkg' command to install them with."
    fi

    warn "About to install: ${missing[*]}"
    info "This downloads a few hundred MB and can take a while."
    if [ "$ASSUME_YES" -eq 0 ]; then
        printf '  Continue? [Y/n] '
        read -r reply </dev/tty || reply="y"
        case "$reply" in
            n|N|no|NO) die "Cancelled. Nothing was installed." ;;
        esac
    fi

    step "Installing ${missing[*]}"
    # -y so pkg never stops to ask; we already asked.
    "$PKG" install -y "${missing[@]}" || die "Package installation failed."
    ok "Packages installed"

    # Re-resolve the paths now that the binaries exist.
    MARIADBD=$(find_cmd mariadbd mysqld)     || MARIADBD=""
    MARIADB=$(find_cmd mariadb mysql)        || MARIADB=""
    INSTALL_DB=$(find_cmd mariadb-install-db mysql_install_db) || INSTALL_DB=""
    PHP_BIN=$(find_cmd php)                  || PHP_BIN=""
}

check_tools() {
    [ -n "$PHP_BIN" ]  || die "The 'php' command is still missing."
    [ -n "$MARIADBD" ] || die "Neither 'mariadbd' nor 'mysqld' was found."
    [ -n "$MARIADB" ]  || die "The MariaDB client ('mariadb' or 'mysql') was not found."

    # mysqli must be present or the whole app is pointless.
    if ! "$PHP_BIN" -m 2>/dev/null | grep -qi '^mysqli$'; then
        die "This PHP build has no mysqli extension.
     Termux's php package includes it, so check that 'php' comes from pkg
     and not from a different source: $PHP_BIN"
    fi

    if [ -z "$INSTALL_DB" ] && [ ! -d "$DATADIR/mysql" ]; then
        die "Need 'mariadb-install-db' to prepare the data directory, but it is missing."
    fi

    # GD is only needed for uploading dish photos in the admin, so a missing
    # copy is a warning rather than a fatal error. Termux's php package
    # enables it, so this normally never fires.
    if ! "$PHP_BIN" -r 'exit(function_exists("imagecreatefromjpeg") ? 0 : 1);' 2>/dev/null; then
        warn "This PHP has no GD extension, so photo uploads in the admin are"
        warn "switched off (the menu still works). On Debian/Ubuntu: sudo apt install php-gd"
    fi
}

# ---------------------------------------------------------------------
#  MariaDB
# ---------------------------------------------------------------------
db_up() { port_open "$DB_PORT"; }

init_datadir() {
    if [ -d "$DATADIR/mysql" ]; then
        ok "MariaDB data directory already initialised"
        return 0
    fi

    step "Initialising MariaDB data directory (first run only)"
    mkdir -p "$DATADIR" "$(dirname "$SOCKET")" || die "Cannot create $DATADIR"

    # On Termux, the fake /etc/passwd maps `root` to this app's uid, which
    # is exactly what the official mariadb package does at install time.
    local user_flag=()
    if [ "$IS_TERMUX" -eq 1 ]; then
        user_flag=(--user=root)
    fi

    "$INSTALL_DB" \
        "${user_flag[@]}" \
        --auth-root-authentication-method=normal \
        --datadir="$DATADIR" >"$RUN_DIR/init-db.log" 2>&1 \
        || {
            warn "Initialisation failed. Last lines of $RUN_DIR/init-db.log:"
            tail -n 15 "$RUN_DIR/init-db.log" 2>/dev/null | sed 's/^/    /'
            die "Could not initialise the MariaDB data directory."
        }

    ok "Data directory ready"
}

db_start() {
    if db_up; then
        ok "MariaDB is already running on port $DB_PORT"
        return 0
    fi

    init_datadir

    step "Starting MariaDB on 127.0.0.1:$DB_PORT"
    # Every path is given explicitly: a stray system my.cnf otherwise
    # points pid-file/socket at locations we may not be allowed to write.
    # The server also binds to loopback only, so nothing on the network
    # can reach it.
    nohup "$MARIADBD" \
        --datadir="$DATADIR" \
        --socket="$SOCKET" \
        --pid-file="$DATADIR/mysqld.pid" \
        --port="$DB_PORT" \
        --bind-address="127.0.0.1" \
        --skip-name-resolve \
        >>"$LOG_DB" 2>&1 &
    printf '%s' "$!" >"$PID_DB"

    # Wait for the port to open (first start can take ~15s).
    local i=0
    while [ "$i" -lt 60 ]; do
        if db_up; then
            ok "MariaDB is ready (pid $(cat "$PID_DB"))"
            return 0
        fi
        # Give up early if the process died instead of just being slow.
        if ! kill -0 "$(cat "$PID_DB")" 2>/dev/null; then
            warn "MariaDB exited during startup. Last lines of $LOG_DB:"
            tail -n 20 "$LOG_DB" 2>/dev/null | sed 's/^/    /'
            die "MariaDB failed to start."
        fi
        i=$((i + 1))
        sleep 0.5
    done

    warn "Timed out waiting for MariaDB. Last lines of $LOG_DB:"
    tail -n 20 "$LOG_DB" 2>/dev/null | sed 's/^/    /'
    die "MariaDB did not come up."
}

db_stop() {
    if [ -f "$PID_DB" ]; then
        local pid
        pid=$(cat "$PID_DB")
        if kill -0 "$pid" 2>/dev/null; then
            step "Stopping MariaDB (pid $pid)"
            # mariadb-admin shutdown is the clean way; fall back to SIGTERM.
            "$MARIADB" --socket="$SOCKET" -u root ${ROOT_PASS:+-p"$ROOT_PASS"} \
                -e 'SHUTDOWN' >/dev/null 2>&1 \
                || kill "$pid" 2>/dev/null
            local i=0
            while [ "$i" -lt 40 ] && kill -0 "$pid" 2>/dev/null; do
                sleep 0.25; i=$((i + 1))
            done
            kill -9 "$pid" 2>/dev/null
            ok "MariaDB stopped"
        fi
        rm -f "$PID_DB"
    fi

    if db_up; then
        warn "Something is still listening on port $DB_PORT — it may have been
     started outside this script (for example by 'sv-enable mariadb')."
    fi
}

# Run SQL as the MySQL root account, over the unix socket.
db_root() {
    "$MARIADB" --socket="$SOCKET" -u root ${ROOT_PASS:+-p"$ROOT_PASS"} "$@"
}

# ---------------------------------------------------------------------
#  Credentials + .env
# ---------------------------------------------------------------------
random_password() {
    # Alphanumeric only, so it is safe to paste into SQL unquoted-escaped.
    head -c 200 /dev/urandom | tr -dc 'A-Za-z0-9' | head -c 24
}

env_value() {   # env_value KEY
    [ -f "$ENV_FILE" ] || return 1
    sed -n "s/^[[:space:]]*$1[[:space:]]*=[[:space:]]*//p" "$ENV_FILE" | head -1
}

ensure_env() {
    step "Checking database credentials"

    if [ -f "$ENV_FILE" ] && [ -n "$(env_value DB_PASS || true)" ]; then
        # Reuse the password we generated last time — and make sure we
        # actually load it into this shell, or we would try to create the
        # MySQL account with an empty password.
        DB_USER="$(env_value DB_USER || true)"; [ -n "$DB_USER" ] || DB_USER="campus"
        DB_NAME="$(env_value DB_NAME || true)"; [ -n "$DB_NAME" ] || DB_NAME="campus_food"
        DB_PASS="$(env_value DB_PASS || true)"

        # Keep the stored port in step with how we're starting the server now.
        if grep -q '^DB_PORT=' "$ENV_FILE" 2>/dev/null; then
            sed -i "s|^DB_PORT=.*|DB_PORT=$DB_PORT|" "$ENV_FILE"
        else
            printf '\nDB_PORT=%s\n' "$DB_PORT" >>"$ENV_FILE"
        fi

        ok "Reusing credentials from .env (user '$DB_USER')"
        return 0
    fi

    DB_PASS="$(random_password)"
    [ -n "$DB_PASS" ] || die "Could not generate a password."

    umask 077
    cat >"$ENV_FILE" <<EOF
# ---------------------------------------------------------------------
#  Campus Food System — local configuration
#  Written by termux-start.sh. Keep this file private: it holds the
#  database password. Real environment variables override these values.
# ---------------------------------------------------------------------
DB_HOST=127.0.0.1
DB_PORT=$DB_PORT
DB_NAME=$DB_NAME
DB_USER=$DB_USER
DB_PASS=$DB_PASS
EOF

    if [ "$HAS_DEMO_DATA" -eq 1 ]; then
        cat >>"$ENV_FILE" <<'EOF'

# Show PHP errors in the browser instead of only logging them.
# This is a DEMO install on your own device, so seeing them is useful.
# On a real canteen server, delete this line (or set it to 0): a stack
# trace in the page can reveal file paths and SQL to a stranger.
APP_DEBUG=1
EOF
    else
        cat >>"$ENV_FILE" <<'EOF'

# Errors are written to storage/logs/app.log and the visitor is shown a
# plain apology. Set APP_DEBUG=1 here only while troubleshooting.
EOF
    fi

    cat >>"$ENV_FILE" <<'EOF'

# Used only by termux-start.sh to administer the server.
# The app itself never needs the MySQL root account.
MYSQL_ROOT_PASSWORD=
EOF
    chmod 600 "$ENV_FILE"
    ok "Generated a random database password and saved it to .env"
    info "(chmod 600, so only your Termux user can read it)"
}

# ---------------------------------------------------------------------
#  Database contents
# ---------------------------------------------------------------------
db_exists() {
    local found
    found=$(db_root -N -B -e "SHOW DATABASES LIKE '$DB_NAME';" 2>/dev/null)
    [ -n "$found" ]
}

create_app_user() {
    # Accounts are created for both hosts because the app connects over
    # TCP (127.0.0.1) while the CLI connects over the unix socket.
    local sql
    sql=$(cat <<SQL
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
)
    db_root -e "$sql" || die "Could not create the application database account."
}

import_sql() {
    local file="$1" label="$2"
    [ -f "$file" ] || die "Missing SQL file: $file"
    info "Importing $label…"
    db_root < "$file" || die "Import of $file failed."
}

ensure_database() {
    step "Setting up the database"

    if db_exists && [ "$FRESH" -eq 1 ]; then
        warn "--fresh given: dropping the existing '$DB_NAME' database"
        db_root -e "DROP DATABASE IF EXISTS \`$DB_NAME\`;" || die "Could not drop the database."
    fi

    if db_exists; then
        ok "Database '$DB_NAME' already exists — keeping your data"
        create_app_user
        return 0
    fi

    import_sql "$PROJECT_DIR/database/schema.sql" "schema"

    if [ "$WITH_SEED" -eq 1 ]; then
        import_sql "$PROJECT_DIR/database/demo-data.sql" "demo data"
        ok "Database created with the sample menu and orders"
    else
        ok "Database created (empty — no sample data)"
    fi

    create_app_user
    ok "Application account '$DB_USER' ready"
}

# ---------------------------------------------------------------------
#  PHP web server
# ---------------------------------------------------------------------
php_running() {
    [ -f "$PID_WEB" ] && kill -0 "$(cat "$PID_WEB")" 2>/dev/null
}

server_url() {
    printf 'http://localhost:%s' "$WEB_PORT"
}

server_start() {   # background
    if php_running; then
        ok "Web server already running on port $WEB_PORT"
        return 0
    fi
    if port_open "$WEB_PORT"; then
        die "Port $WEB_PORT is already in use. Pick another with --port 8081"
    fi

    step "Starting the PHP server on $BIND_ADDR:$WEB_PORT"
    nohup "$PHP_BIN" -S "$BIND_ADDR:$WEB_PORT" -t "$PROJECT_DIR/public" \
        >>"$LOG_WEB" 2>&1 &
    printf '%s' "$!" >"$PID_WEB"

    local i=0
    while [ "$i" -lt 40 ]; do
        if port_open "$WEB_PORT"; then
            ok "Web server is ready (pid $(cat "$PID_WEB"))"
            return 0
        fi
        if ! kill -0 "$(cat "$PID_WEB")" 2>/dev/null; then
            warn "The PHP server exited. Last lines of $LOG_WEB:"
            tail -n 20 "$LOG_WEB" 2>/dev/null | sed 's/^/    /'
            die "Could not start the web server."
        fi
        i=$((i + 1))
        sleep 0.25
    done

    die "Timed out starting the web server."
}

# Quick end-to-end check that PHP can actually reach MariaDB.
verify_app() {
    step "Verifying the app can reach the database"

    local out
    out=$(cd "$PROJECT_DIR" && "$PHP_BIN" -r '
        require "config/db.php";
        $n = db()->query("SELECT COUNT(*) AS n FROM menu_items")->fetch_assoc()["n"];
        $u = db()->query("SELECT COUNT(*) AS n FROM users")->fetch_assoc()["n"];
        echo "items=$n users=$u";
    ' 2>&1)

    case "$out" in
        *items=*)
            ok "Connected to MariaDB via mysqli (${out})" ;;
        *)
            warn "PHP could not query the database:"
            printf '%s\n' "$out" | tail -n 10 | sed 's/^/    /'
            die "The database is up but the app cannot use it." ;;
    esac
}

show_endpoints() {
    local ip
    ip=$(lan_ip)

    printf '\n%s┌──────────────────────────────────────────────────────────┐%s\n' "$B$GRN" "$R"
    printf '%s│%s  %sCampus Food is running%s                                  %s│%s\n' "$B$GRN" "$R" "$B" "$R" "$B$GRN" "$R"
    printf '%s└──────────────────────────────────────────────────────────┘%s\n' "$B$GRN" "$R"
    printf '   %sOpen:%s  %s%s/%s\n' "$B" "$R" "$B" "$(server_url)" "$R"

    if [ "$BIND_ADDR" = "0.0.0.0" ] && [ -n "$ip" ]; then
        printf '   %sWi-Fi:%s http://%s:%s  (other devices on your network)\n' "$B" "$R" "$ip" "$WEB_PORT"
    elif [ -n "$ip" ]; then
        printf '   %sTip:%s  run with --lan to open it from another device\n' "$B" "$R"
    fi

    printf '   %sDB:%s    MariaDB on 127.0.0.1:%s (%s)\n' "$B" "$R" "$DB_PORT" "$DB_NAME"
    printf '   %sStop:%s  ./termux-start.sh down\n' "$B" "$R"

    if [ "$HAS_DEMO_DATA" -eq 1 ] && db_exists 2>/dev/null; then
        printf '\n   %sThis is a DEMO install%s — stock photos of generic dishes,\n' "$B" "$R"
        printf '   and accounts with publicly known passwords.\n'
        printf '   To go live: sign in as admin, then Admin > Menu items >\n'
        printf '   "Remove all demo data", and create your own admin account.\n'
        printf '\n   %sDemo logins%s\n' "$B" "$R"
        printf '     admin@campus.edu %s/ admin123%s    (canteen admin)\n' "$DIM" "$R"
        printf '     maria@campus.edu %s/ campus123%s   (student)\n' "$DIM" "$R"
    elif db_exists 2>/dev/null; then
        printf '\n   %sThis is the PRODUCTION build%s — no sample dishes, no demo\n' "$B" "$R"
        printf '   accounts, no stock photos. Start here:\n'
        printf '     1. open %s/register.php and create your own account\n' "$(server_url)"
        printf '     2. make it the canteen admin:\n'
        printf '        php tools/make-admin.php --promote you@campus.edu\n'
        printf '     3. sign in, then Admin > Menu items and add your real dishes\n'
    fi
    printf '\n'
}

# ---------------------------------------------------------------------
#  Commands
# ---------------------------------------------------------------------
ensure_run_dir() {
    mkdir -p "$RUN_DIR" || die "Cannot create $RUN_DIR"
    # Keep the MariaDB socket directory in place.
    mkdir -p "$(dirname "$SOCKET")" 2>/dev/null || true
}

cmd_run() {   # foreground
    install_packages
    check_tools
    ensure_run_dir
    db_start
    ensure_env
    ensure_database
    verify_app

    if php_running; then
        ok "Web server already running"
        show_endpoints
        return 0
    fi
    if port_open "$WEB_PORT"; then
        die "Port $WEB_PORT is already in use. Pick another with --port 8081"
    fi

    step "Starting the PHP server on $BIND_ADDR:$WEB_PORT"
    show_endpoints
    printf '   %sPress Ctrl+C to stop.%s\n\n' "$DIM" "$R"

    # Survive Ctrl+C here so the cleanup below still runs after PHP exits.
    trap 'true' INT TERM

    "$PHP_BIN" -S "$BIND_ADDR:$WEB_PORT" -t "$PROJECT_DIR/public" &
    printf '%s' "$!" >"$PID_WEB"

    wait "$(cat "$PID_WEB")"

    # Reached when PHP exits on its own or via Ctrl+C.
    cmd_down
}

cmd_up() {    # background
    install_packages
    check_tools
    ensure_run_dir
    db_start
    ensure_env
    ensure_database
    verify_app
    server_start
    show_endpoints
}

cmd_down() {
    step "Shutting down"

    if [ -f "$PID_WEB" ]; then
        local pid
        pid=$(cat "$PID_WEB")
        if kill -0 "$pid" 2>/dev/null; then
            kill "$pid" 2>/dev/null
            local i=0
            while [ "$i" -lt 20 ] && kill -0 "$pid" 2>/dev/null; do sleep 0.2; i=$((i + 1)); done
            kill -9 "$pid" 2>/dev/null
            ok "Web server stopped"
        else
            # Common after Ctrl+C: PHP took the signal itself and is
            # already gone, so there is nothing left to kill.
            ok "Web server stopped"
        fi
        rm -f "$PID_WEB"
    elif port_open "$WEB_PORT"; then
        warn "Nothing started by this script is on port $WEB_PORT, leaving it alone"
    else
        info "Web server was not running"
    fi

    db_stop
    printf '\n'
}

cmd_status() {
    step "Status"

    if db_up; then
        printf '  %s✓%s MariaDB    running on 127.0.0.1:%s\n' "$GRN" "$R" "$DB_PORT"
    else
        printf '  %s✗%s MariaDB    not running\n' "$RED" "$R"
    fi

    if php_running; then
        printf '  %s✓%s Web server running on port %s  →  %s\n' "$GRN" "$R" "$WEB_PORT" "$(server_url)"
    elif port_open "$WEB_PORT"; then
        printf '  %s?%s Web server port %s is busy (not started by this script)\n' "$YEL" "$R" "$WEB_PORT"
    else
        printf '  %s✗%s Web server not running\n' "$RED" "$R"
    fi

    if db_up; then
        local n
        n=$(db_root -N -B -e \
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME';" 2>/dev/null)
        if [ -n "$n" ] && [ "$n" != "0" ]; then
            local items orders
            items=$(db_root -N -B -e "SELECT COUNT(*) FROM \`$DB_NAME\`.menu_items;" 2>/dev/null)
            orders=$(db_root -N -B -e "SELECT COUNT(*) FROM \`$DB_NAME\`.orders;" 2>/dev/null)
            printf '  %s·%s Database   %s (%s tables, %s menu items, %s orders)\n' \
                "$DIM" "$R" "$DB_NAME" "$n" "${items:-?}" "${orders:-?}"
        else
            printf '  %s!%s Database   %s not set up yet — run: ./termux-start.sh\n' "$YEL" "$R" "$DB_NAME"
        fi
    fi

    [ -f "$ENV_FILE" ] && printf '  %s·%s Config     .env present\n' "$DIM" "$R"
    printf '\n'
}

cmd_logs() {
    ensure_run_dir
    touch "$LOG_DB" "$LOG_WEB"
    info "Tailing MariaDB and web server logs — Ctrl+C to stop"
    trap 'exit 0' INT
    tail -n 30 -f "$LOG_DB" "$LOG_WEB"
}

cmd_db() {
    db_up || die "MariaDB is not running. Start it with: ./termux-start.sh up"
    info "Opening a MariaDB shell on '$DB_NAME' (\\q to quit)"
    "$MARIADB" --socket="$SOCKET" -u root ${ROOT_PASS:+-p"$ROOT_PASS"} "$DB_NAME"
}

cmd_reset() {
    check_tools
    ensure_run_dir
    db_start
    ensure_env

    warn "This DELETES the '$DB_NAME' database and all orders in it."
    if [ "$ASSUME_YES" -eq 0 ]; then
        printf '  Type "yes" to continue: '
        read -r reply </dev/tty || reply=""
        [ "$reply" = "yes" ] || die "Cancelled — nothing was changed."
    fi

    db_root -e "DROP DATABASE IF EXISTS \`$DB_NAME\`;" || die "Could not drop the database."
    ok "Old database dropped"

    import_sql "$PROJECT_DIR/database/schema.sql" "schema"
    if [ "$WITH_SEED" -eq 1 ]; then
        import_sql "$PROJECT_DIR/database/demo-data.sql" "demo data"
    fi
    create_app_user
    verify_app
    ok "Database rebuilt and ready"
    printf '\n'
}

cmd_sql() {
    local files=()
    if [ $# -gt 0 ]; then
        files=("$@")
    else
        files=("$PROJECT_DIR/database/schema.sql")
        [ "$WITH_SEED" -eq 1 ] && files+=("$PROJECT_DIR/database/demo-data.sql")
    fi

    check_tools
    ensure_run_dir
    db_start
    ensure_env

    for f in "${files[@]}"; do
        case "$f" in
            /*) ;;
            *) f="$PROJECT_DIR/$f" ;;
        esac
        import_sql "$f" "$(basename "$f")"
    done

    create_app_user
    ok "SQL applied"
    printf '\n'
}

cmd_cred() {
    if [ ! -f "$ENV_FILE" ]; then
        die "No .env yet. Run ./termux-start.sh up first."
    fi
    step "Contents of .env"
    sed 's/^/  /' "$ENV_FILE"
    printf '\n'
    info "These are read by config/db.php automatically."
    info "Delete .env and re-run the script to generate a fresh password."
}

# ---------------------------------------------------------------------
#  Dispatch
# ---------------------------------------------------------------------
case "$COMMAND" in
    run)     cmd_run ;;
    up)      cmd_up ;;
    down)    cmd_down ;;
    restart) cmd_down; cmd_up ;;
    status)  cmd_status ;;
    logs)    cmd_logs ;;
    db)      cmd_db ;;
    reset)   cmd_reset ;;
    sql)     cmd_sql "${POSITIONAL[@]}" ;;   # remaining args are file paths
    cred)    cmd_cred ;;
esac

exit 0
