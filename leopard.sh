#!/bin/sh
# Runtime launcher. Lives at the root of the unpacked bundle as ./leopard.
# All state (database, config, sites, logs, pids) lives in $LEOPARD_HOME.
# Commands that print data (status, version, config, site, doctor) accept
# --json. Exit codes: 0 ok, 1 error, 2 bad usage, 3 not found, 4 already
# exists, 5 server not running.
set -eu
R=$(cd "$(dirname "$(readlink -f "$0")")" && pwd)
# Run through the single file, LEOPARD_HOME is set. Run through a symlink made
# by `leopard link`, the home is where this runtime was unpacked: <home>/runtime/<id>.
H=${LEOPARD_HOME:-$(cd "$R/../.." && pwd)}
RUN=$H/run
CONF=$H/conf/leopard.conf
mkdir -p "$H/conf/sites" "$H/logs" "$RUN"

# Settings: environment first, then what an earlier command saved, then defaults.
cfg_port='' cfg_db_port='' cfg_url='' cfg_docroot='' cfg_xdebug='' cfg_bin=''
# shellcheck disable=SC1090
[ -f "$CONF" ] && . "$CONF"
# Homes made before leopard.conf kept the docroot in its own file.
[ -z "$cfg_docroot" ] && [ -f "$H/conf/docroot" ] && cfg_docroot=$(cat "$H/conf/docroot")
PORT=${LEOPARD_PORT:-${cfg_port:-8080}}
DB_PORT=${LEOPARD_DB_PORT:-${cfg_db_port:-3306}}
URL_SET=${LEOPARD_URL:-$cfg_url}
URL=${URL_SET:-http://localhost:$PORT}
URL=${URL%/}
XDEBUG=${cfg_xdebug:-off}
BIN=${LEOPARD_BIN:-${cfg_bin:-$R/leopard}}
SOCK=$RUN/mysqld.sock
# Unix socket paths are limited to about 107 bytes.
[ ${#SOCK} -gt 100 ] && SOCK=/tmp/leopard-$(printf %s "$H" | cksum | cut -d' ' -f1).sock
export PATH="$R/bin:$PATH"
JSON=0

alive() { [ -f "$1" ] && running "$(cat "$1")"; }
# A pid counts as ours only if its command line names this home. That rejects
# stale pid files whose pid now belongs to another process, and zombies left
# by a container PID 1 that never reaps (their command line is empty).
running() { tr '\0' '\n' 2>/dev/null <"/proc/$1/cmdline" | grep -qF "$H/"; }
root() { [ "$(id -u)" = 0 ]; }
# port_free <address> <port>: true if nothing listens there yet.
# shellcheck disable=SC2016  # $argv is PHP's, not the shell's.
port_free() { php -r 'exit(@stream_socket_server("tcp://$argv[1]:$argv[2]") ? 0 : 1);' "$1" "$2" 2>/dev/null; }

# die <exit code> <error code> <message>
die() {
  if [ "$JSON" = 1 ]; then
    m=$(printf %s "$3" | tr '\n\r\t' '   ' | tr -d '\000-\037' | sed 's/\\/\\\\/g; s/"/\\"/g')
    printf '{"ok":false,"error":{"code":"%s","message":"%s"}}\n' "$2" "$m"
  else
    echo "leopard: $3" >&2
  fi
  exit "$1"
}

# The PHP half of the launcher prints status, versions, sites, and checks.
lphp() {
  LP_HOME=$H LP_RUNTIME=$R LP_PORT=$PORT LP_DB_PORT=$DB_PORT LP_URL=$URL LP_SOCK=$SOCK \
    LP_XDEBUG=$XDEBUG LP_DOCROOT=$cfg_docroot exec php "$R/leopard.php" "$@"
}

# Writes the cfg_* values. Only a successful start copies the environment's
# values into them; other commands change one setting and keep the rest.
save_config() {
  for v in "$cfg_port" "$cfg_db_port" "$cfg_url" "$cfg_docroot" "$cfg_xdebug" "$cfg_bin"; do
    case $v in *"'"* | *"
"*) die 2 bad_value "Settings cannot contain a single quote or a line break." ;; esac
  done
  {
    echo "cfg_port='$cfg_port'"
    echo "cfg_db_port='$cfg_db_port'"
    echo "cfg_url='$cfg_url'"
    echo "cfg_docroot='$cfg_docroot'"
    echo "cfg_xdebug='$cfg_xdebug'"
    echo "cfg_bin='$cfg_bin'"
  } >"$CONF.$$"
  mv -f "$CONF.$$" "$CONF"
}

write_php_ini() {
  # Other commands may be reading these files right now, so replace each whole.
  cat >"$H/conf/php.ini.$$" <<EOF
extension_dir = "$R/php/ext"
memory_limit = 1024M
upload_max_filesize = 128M
post_max_size = 128M
max_execution_time = 300
max_input_vars = 5000
display_errors = On
error_reporting = E_ALL
log_errors = On
error_log = "$H/logs/php-error.log"
date.timezone = UTC
openssl.cafile = "$R/conf/ca-certificates.crt"
curl.cainfo = "$R/conf/ca-certificates.crt"
mysqli.default_socket = "$SOCK"
pdo_mysql.default_socket = "$SOCK"
xdebug.mode = "$XDEBUG"
xdebug.discover_client_host = 1
xdebug.output_dir = "$H/logs/xdebug"
EOF
  mv -f "$H/conf/php.ini.$$" "$H/conf/php.ini"
  # The MariaDB client reads $MYSQL_HOME/my.cnf, so plain `mysql` logs in as root.
  # The server listens only on 127.0.0.1, so skip the TLS certificate check
  # that MariaDB 11 clients warn about on every import and dump.
  printf '[client]\nuser = root\nsocket = "%s"\nskip-ssl-verify-server-cert\n' "$SOCK" >"$H/conf/my.cnf.$$"
  mv -f "$H/conf/my.cnf.$$" "$H/conf/my.cnf"
  # WP-CLI cannot see Apache's modules from the command line. Tell it about
  # mod_rewrite so `wp rewrite flush --hard` writes .htaccess. Keep the user's
  # own global WP-CLI config.
  {
    echo "apache_modules: [mod_rewrite]"
    [ -f "$HOME/.wp-cli/config.yml" ] && printf '_:\n  inherit: %s\n' "$HOME/.wp-cli/config.yml"
  } >"$H/conf/wp-cli.yml.$$"
  mv -f "$H/conf/wp-cli.yml.$$" "$H/conf/wp-cli.yml"
}

write_config() {
  docroot=$1
  # Apache will not serve pages as root. Run workers as the owner of the
  # document root so the app can write uploads, or as nobody if that is root.
  owner=$(stat -c %u "$docroot")
  [ "$owner" = 0 ] && owner=65534
  d=$docroot
  while root && [ "$d" != / ]; do
    d=$(dirname "$d")
    case $(stat -c %A "$d") in
      *[xt]) ;;
      *) echo "Warning: Apache runs as uid $owner and cannot enter $d. Pages will return 403." >&2 ;;
    esac
  done
  user=""
  root && user="User #$owner
Group #$(stat -c %g "$docroot" | sed 's/^0$/65534/')"
  cat >"$H/conf/httpd.conf" <<EOF
ServerRoot "$R"
ServerName localhost
Listen $PORT
$user
PidFile "$RUN/apache.pid"
DefaultRuntimeDir "$RUN"
Mutex "file:$RUN" default
ErrorLog "$H/logs/apache-error.log"
LogFormat "%h %t \"%r\" %>s %b %D" leopard
CustomLog "$H/logs/apache-access.log" leopard
LoadModule mpm_prefork_module modules/mod_mpm_prefork.so
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule authz_host_module modules/mod_authz_host.so
LoadModule access_compat_module modules/mod_access_compat.so
LoadModule alias_module modules/mod_alias.so
LoadModule dir_module modules/mod_dir.so
LoadModule mime_module modules/mod_mime.so
LoadModule env_module modules/mod_env.so
LoadModule setenvif_module modules/mod_setenvif.so
LoadModule headers_module modules/mod_headers.so
LoadModule expires_module modules/mod_expires.so
LoadModule filter_module modules/mod_filter.so
LoadModule deflate_module modules/mod_deflate.so
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule php_module $(cd "$R" && ls modules/libphp*.so)
StartServers 2
MaxRequestWorkers 20
TypesConfig conf/mime.types
PHPIniDir "$H/conf"
DocumentRoot "$docroot"
<Directory "$docroot">
  Options FollowSymLinks
  AllowOverride All
  Require all granted
</Directory>
DirectoryIndex index.php index.html
<FilesMatch "\.php$">
  SetHandler application/x-httpd-php
</FilesMatch>
# One file per site, written by "leopard site create": an Alias /<name>.
IncludeOptional "$H/conf/sites/*.conf"
EOF
}

start_db() {
  # A running server keeps its port. Restart it when the port changed.
  if alive "$RUN/mariadb.pid"; then
    # No record means a server started by an older Leopard: keep it.
    if [ "$(cat "$RUN/db.port" 2>/dev/null || echo "$DB_PORT")" = "$DB_PORT" ]; then
      echo "$DB_PORT" >"$RUN/db.port"
      return
    fi
    port_free 127.0.0.1 "$DB_PORT" || die 1 db_start_failed "Port $DB_PORT is in use. MariaDB keeps running on its old port."
    stop "$RUN/mariadb.pid"
  fi
  # Copy the pre-built database once. Copy to a temp dir and rename, so a
  # crash halfway never leaves a half-copied data dir behind.
  if [ ! -d "$H/mysql" ]; then
    rm -rf "$H/mysql.tmp"
    cp -R "$R/seed/mysql" "$H/mysql.tmp"
    mv "$H/mysql.tmp" "$H/mysql"
  fi
  rm -f "$SOCK" "$RUN/mariadb.pid"
  set -- --no-defaults --basedir="$R" --datadir="$H/mysql" --socket="$SOCK" \
    --pid-file="$RUN/mariadb.pid" --port="$DB_PORT" --bind-address=127.0.0.1 \
    --plugin-dir="$R/mariadb/plugin" --lc-messages-dir="$R/mariadb/share" \
    --character-sets-dir="$R/mariadb/share/charsets" --log-error="$H/logs/mariadb.log" \
    --skip-log-bin --innodb-buffer-pool-size=128M
  root && set -- "$@" --user=root
  nohup mariadbd "$@" >/dev/null 2>&1 9>&- &
  pid=$!
  i=0
  until mariadb-admin --socket="$SOCK" -uroot ping >/dev/null 2>&1; do
    i=$((i + 1))
    if [ $i -gt 300 ] || ! running $pid; then
      die 1 db_start_failed "MariaDB did not start. Is port $DB_PORT free? See $H/logs/mariadb.log"
    fi
    sleep 0.1
  done
  echo "$DB_PORT" >"$RUN/db.port"
}

start_web() {
  # A graceful reload cannot move Apache to a new port: if the new port is
  # busy, Apache dies after the reload. So a port change is a full restart.
  if alive "$RUN/apache.pid" && [ "$(cat "$RUN/web.port" 2>/dev/null || echo "$PORT")" != "$PORT" ]; then
    port_free 0.0.0.0 "$PORT" || die 1 web_start_failed "Port $PORT is in use. Apache keeps running on its old port."
    stop "$RUN/apache.pid"
  fi
  if alive "$RUN/apache.pid"; then
    apache2 -f "$H/conf/httpd.conf" -k graceful 9>&-
  else
    # Apache trusts any live pid in its pid file, so drop one we know is stale.
    rm -f "$RUN/apache.pid"
    apache2 -f "$H/conf/httpd.conf" -k start 9>&- >>"$H/logs/apache-error.log" 2>&1 || true
  fi
  # Apache forks into the background and writes its pid file a moment later.
  i=0
  until alive "$RUN/apache.pid"; do
    i=$((i + 1))
    [ $i -gt 50 ] && die 1 web_start_failed "Apache did not start. Is port $PORT free? See $H/logs/apache-error.log"
    sleep 0.1
  done
  echo "$PORT" >"$RUN/web.port"
}

# Reload Apache and PHP settings without dropping requests, if Apache runs.
reload_web() {
  alive "$RUN/apache.pid" && apache2 -f "$H/conf/httpd.conf" -k graceful 9>&-
  return 0
}

stop() {
  # $1 is the pid file. Wait for the process to exit so a following start is clean.
  alive "$1" || return 0
  pid=$(cat "$1")
  kill "$pid" 2>/dev/null || true
  i=0
  while running "$pid"; do
    i=$((i + 1))
    [ $i -gt 300 ] && kill -9 "$pid" 2>/dev/null
    sleep 0.1
  done
}

start() {
  [ -d "$1" ] || die 3 not_found "Document root does not exist: $1"
  docroot=$(cd "$1" && pwd)
  write_config "$docroot"
  start_db
  start_web
  # Saved only once both servers run, so a bad port is never kept for the
  # next start (or for systemd at boot).
  cfg_port=$PORT cfg_db_port=$DB_PORT cfg_url=$URL_SET cfg_docroot=$docroot cfg_bin=$BIN
  save_config
  # After an upgrade, links from `leopard link` still point at the old runtime.
  if [ -L "$H/bin/php" ] && [ "$(readlink "$H/bin/php")" != "$R/bin/php" ]; then link "$H/bin" >/dev/null; fi
}

stop_all() {
  stop "$RUN/apache.pid"
  stop "$RUN/mariadb.pid"
}

# Only one start, stop, or restart runs at a time, so two first starts never
# copy the database into the same place. Servers start with fd 9 closed, so
# they do not hold the lock after this command exits.
lock() {
  exec 9>"$H/.lock"
  command -v flock >/dev/null && flock 9
  return 0
}

# By default the links go in Leopard's own folder, so nothing on the system
# changes. A system folder gets links only when the user names it.
link() {
  dir=${1:-$H/bin}
  mkdir -p "$dir"
  for t in leopard php wp composer mysql mysqladmin mysqldump; do
    src=$R/bin/$t
    [ "$t" = leopard ] && src=$R/leopard
    # Replace our own older links, but never a tool the system installed.
    if [ -e "$dir/$t" ] && ! readlink "$dir/$t" | grep -q '/runtime/'; then
      echo "Skipped $dir/$t: it already exists and is not from Leopard." >&2
      continue
    fi
    ln -sfn "$src" "$dir/$t"
  done
  echo "Linked leopard, php, wp, composer, mysql, mysqladmin, and mysqldump in $dir."
  case :$PATH: in
    *":$dir:"*) ;;
    *) echo "To use them in this shell: export PATH=\"$dir:\$PATH\"" ;;
  esac
}

# Start at boot with systemd. Only on request: this is the one command that
# writes outside $LEOPARD_HOME (a systemd unit file).
service() {
  command -v systemctl >/dev/null || die 1 no_systemd "systemd is not available here."
  if root; then
    unit=/etc/systemd/system/leopard.service sc=systemctl target=multi-user.target
  else
    unit=${XDG_CONFIG_HOME:-$HOME/.config}/systemd/user/leopard.service sc="systemctl --user" target=default.target
  fi
  case ${1:-status} in
    install)
      mkdir -p "$(dirname "$unit")"
      cat >"$unit" <<EOF
[Unit]
Description=Leopard (Apache, PHP, MariaDB) for $H
After=network-online.target

[Service]
Type=oneshot
RemainAfterExit=yes
Environment=LEOPARD_HOME=$H
ExecStart=$BIN start
ExecStop=$BIN stop

[Install]
WantedBy=$target
EOF
      $sc daemon-reload
      $sc enable leopard.service
      echo "Installed $unit. Leopard starts at boot with: $BIN start"
      if ! root && [ "$(loginctl show-user "$(id -un)" -p Linger --value 2>/dev/null)" != yes ]; then
        echo "User services start at boot only with lingering on. Run once: sudo loginctl enable-linger $(id -un)"
      fi
      ;;
    uninstall)
      $sc disable leopard.service 2>/dev/null || true
      rm -f "$unit"
      $sc daemon-reload
      echo "Removed $unit."
      ;;
    status)
      if [ -f "$unit" ]; then echo "installed: $unit ($($sc is-enabled leopard.service 2>/dev/null || true))"; else echo "not installed"; fi
      ;;
    *) die 2 usage "Usage: leopard service install|uninstall|status" ;;
  esac
}

logs() {
  n=50 follow=0 files=""
  while [ $# -gt 0 ]; do
    case $1 in
      -f | --follow) follow=1 ;;
      -n)
        case ${2:-} in '' | *[!0-9]*) die 2 usage "logs -n needs a number" ;; esac
        n=$2
        shift
        ;;
      apache | web) files="$files $H/logs/apache-error.log" ;;
      access) files="$files $H/logs/apache-access.log" ;;
      php) files="$files $H/logs/php-error.log" ;;
      mariadb | db) files="$files $H/logs/mariadb.log" ;;
      *) die 2 usage "Usage: leopard logs [apache|access|php|mariadb] [-n N] [-f]" ;;
    esac
    shift
  done
  [ -n "$files" ] || files=$(ls "$H"/logs/*.log 2>/dev/null || true)
  [ -n "$files" ] || exit 0
  # shellcheck disable=SC2086
  if [ $follow = 1 ]; then exec tail -n "$n" -f $files; else tail -n "$n" $files; fi
}

xdebug() {
  if [ $# -gt 0 ]; then
    for m in $(echo "$1" | tr , ' '); do
      case $m in
        off | develop | coverage | debug | gcstats | profile | trace) ;;
        *) die 2 usage "Unknown Xdebug mode: $m. Use off, develop, coverage, debug, gcstats, profile, or trace (comma-separated)." ;;
      esac
    done
    XDEBUG=$1 cfg_xdebug=$1
    save_config
    write_php_ini
    reload_web
  fi
  if [ $JSON = 1 ]; then
    printf '{"ok":true,"xdebug_mode":"%s"}\n' "$XDEBUG"
  else
    echo "Xdebug mode: $XDEBUG. For one command only, set XDEBUG_MODE instead."
  fi
}

config() {
  case ${1:-} in
    "") lphp config ;;
    set)
      [ $# = 3 ] || die 2 usage "Usage: leopard config set port|db_port|url VALUE"
      case $2 in
        port | db_port) case $3 in '' | *[!0-9]*) die 2 bad_value "Not a port: $3" ;; esac ;;
        url) case $3 in http://?* | https://?*) ;; *) die 2 bad_value "The url must start with http:// or https://" ;; esac
             case $3 in *[[:space:]]*) die 2 bad_value "The url cannot contain spaces." ;; esac ;;
        *) die 2 usage "Unknown setting: $2. Settings: port, db_port, url" ;;
      esac
      case $2 in
        port) cfg_port=$3 ;;
        db_port) cfg_db_port=$3 ;;
        url) cfg_url=${3%/} ;;
      esac
      save_config
      if [ $JSON = 1 ]; then
        PORT=${cfg_port:-8080} DB_PORT=${cfg_db_port:-3306} URL=${cfg_url:-http://localhost:${cfg_port:-8080}}
        lphp config --json
      fi
      echo "Saved $2=$3. Run \`leopard restart\` to apply it." >&2
      ;;
    *) die 2 usage "Usage: leopard config [set KEY VALUE] [--json]" ;;
  esac
}

usage() {
  cat <<EOF
Usage: leopard <command> [--json]

Server
  start [docroot]     Start Apache, PHP, and MariaDB. Serves docroot at / (default:
                      the last docroot, else the current folder). Safe to rerun.
  stop | restart
  status              Ports, URLs, database login, sites, and whether each server runs
  doctor              Check the servers and every site; exits 1 if a check fails
  logs [apache|access|php|mariadb] [-n N] [-f]
  xdebug [MODE]       Show or set the Xdebug mode: off (default), debug, coverage, ...
  config [set KEY V]  Show settings, or save port, db_port, or url (the base URL
                      that browsers use, like http://100.1.2.3:8080)
  service install|uninstall|status
                      Start at boot with systemd. The only command that writes
                      outside \$LEOPARD_HOME.

Sites (WordPress, served at <url>/<name>)
  site create NAME [--dir PATH] [--plugin [NAME=]PATH]... [--activate PLUGIN]...
                   [--import-sql FILE [--content ZIP] [--from-url URL]]
                   [--wp-version V] [--title T] [--admin-user U] [--admin-password P]
  site list | site info NAME | site path NAME | site delete NAME
  site wp NAME ARGS...  Run WP-CLI against that site

Tools (bundled)
  php | wp | composer | mysql ...   mysql logs in as root
  shell [-c CMD]      A shell with php, wp, composer, mysql, mysqladmin, mysqldump,
                      and leopard first on PATH
  link [dir]          Link those tools into dir (default: \$LEOPARD_HOME/bin)
  version             Versions of Apache, PHP, and MariaDB
  skill               Print the guide for AI agents (SKILL.md)

Settings come from the environment first, then from earlier runs:
  LEOPARD_HOME ($H)
  LEOPARD_PORT ($PORT)  LEOPARD_DB_PORT ($DB_PORT)  LEOPARD_URL ($URL)
Exit codes: 0 ok, 1 error, 2 bad usage, 3 not found, 4 already exists, 5 not running.
EOF
}

cmd=${1:-help}
[ $# -gt 0 ] && shift
# Strip --json from Leopard's own commands. Tools such as wp keep their args.
case $cmd in
  site) case " $* " in *" --json "*) JSON=1 ;; esac ;;
  php | wp | composer | mysql | shell) ;;
  *)
    for a; do
      shift
      if [ "$a" = --json ]; then JSON=1; else set -- "$@" "$a"; fi
    done
    ;;
esac
# php.ini holds absolute paths into this bundle, so every command rewrites it.
write_php_ini
case $cmd in
  start)
    lock
    start "${1:-${cfg_docroot:-.}}"
    if [ $JSON = 1 ]; then lphp status --json; else lphp status; fi
    ;;
  stop)
    lock
    stop_all
    if [ $JSON = 1 ]; then lphp status --json; fi
    ;;
  restart)
    lock
    stop_all
    start "${cfg_docroot:-.}"
    if [ $JSON = 1 ]; then lphp status --json; else lphp status; fi
    ;;
  status | version | doctor)
    if [ $JSON = 1 ]; then lphp "$cmd" --json; else lphp "$cmd"; fi
    ;;
  config)
    if [ $JSON = 1 ] && [ $# = 0 ]; then lphp config --json; else config "$@"; fi
    ;;
  site)
    if [ "${1:-}" = wp ]; then
      [ $# -ge 2 ] || die 2 usage "Usage: leopard site wp NAME ARGS..."
      dir=$(LP_HOME=$H LP_RUNTIME=$R LP_URL=$URL php "$R/leopard.php" site path "$2") || exit $?
      shift 2
      exec wp --path="$dir" "$@"
    fi
    # Site changes need Apache to pick up the new Alias, and a lock so two
    # agents never create the same site at once. The PHP side does both.
    lphp site "$@"
    ;;
  php | wp | composer) exec "$cmd" "$@" ;;
  mysql) exec mariadb "$@" ;;
  logs) logs "$@" ;;
  xdebug) xdebug "$@" ;;
  link) link "${1:-}" ;;
  service) service "$@" ;;
  # A child shell with the bundled tools first on PATH. Exit it to go back.
  shell)
    echo "Leopard shell: php, wp, composer, mysql, mysqladmin, mysqldump, and leopard are on PATH. Type exit to leave." >&2
    LEOPARD_SHELL=1 exec "${SHELL:-/bin/sh}" "$@"
    ;;
  skill) cat "$R/SKILL.md" ;;
  help | -h | --help) usage ;;
  *) usage >&2; die 2 usage "Unknown command: $cmd" ;;
esac
