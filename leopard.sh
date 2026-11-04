#!/bin/sh
# Runtime launcher. Lives at the root of the unpacked bundle as ./leopard.
# All state (database, config, logs, pids) lives in $LEOPARD_HOME.
set -eu
R=$(cd "$(dirname "$(readlink -f "$0")")" && pwd)
# Run through the single file, LEOPARD_HOME is set. Run through a symlink made
# by `leopard link`, the home is where this runtime was unpacked: <home>/runtime/<id>.
H=${LEOPARD_HOME:-$(cd "$R/../.." && pwd)}
PORT=${LEOPARD_PORT:-8080}
DB_PORT=${LEOPARD_DB_PORT:-3306}
RUN=$H/run
SOCK=$RUN/mysqld.sock
# Unix socket paths are limited to about 107 bytes.
[ ${#SOCK} -gt 100 ] && SOCK=/tmp/leopard-$(printf %s "$H" | cksum | cut -d' ' -f1).sock
export PATH="$R/bin:$PATH"

alive() { [ -f "$1" ] && running "$(cat "$1")"; }
# A pid counts as ours only if its command line names this home. That rejects
# stale pid files whose pid now belongs to another process, and zombies left
# by a container PID 1 that never reaps (their command line is empty).
running() { tr '\0' '\n' 2>/dev/null <"/proc/$1/cmdline" | grep -qF "$H/"; }
root() { [ "$(id -u)" = 0 ]; }

write_php_ini() {
  mkdir -p "$H/conf" "$H/logs" "$RUN"
  # Other commands may be reading php.ini right now, so replace it whole.
  cat >"$H/conf/php.ini.$$" <<EOF
extension_dir = "$R/php/ext"
memory_limit = 512M
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
EOF
  mv -f "$H/conf/php.ini.$$" "$H/conf/php.ini"
  # The MariaDB client reads $MYSQL_HOME/my.cnf, so plain `mysql` logs in as root.
  printf '[client]\nuser = root\nsocket = "%s"\n' "$SOCK" >"$H/conf/my.cnf.$$"
  mv -f "$H/conf/my.cnf.$$" "$H/conf/my.cnf"
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
EOF
  echo "$docroot" >"$H/conf/docroot"
}

start_db() {
  alive "$RUN/mariadb.pid" && return
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
      echo "MariaDB did not start. See $H/logs/mariadb.log" >&2
      exit 1
    fi
    sleep 0.1
  done
}

start_web() {
  if alive "$RUN/apache.pid"; then
    apache2 -f "$H/conf/httpd.conf" -k graceful 9>&-
  else
    # Apache trusts any live pid in its pid file, so drop one we know is stale.
    rm -f "$RUN/apache.pid"
    apache2 -f "$H/conf/httpd.conf" -k start 9>&-
  fi
  # Apache forks into the background and writes its pid file a moment later.
  i=0
  until alive "$RUN/apache.pid"; do
    i=$((i + 1))
    [ $i -gt 50 ] && { echo "Apache did not start. See $H/logs/apache-error.log" >&2; exit 1; }
    sleep 0.1
  done
}

stop() {
  # $1 is the pid file. Wait for the process to exit so a following start is clean.
  alive "$1" || return 0
  pid=$(cat "$1")
  kill "$pid"
  while running "$pid"; do sleep 0.1; done
}

status() {
  for s in apache mariadb; do
    if alive "$RUN/$s.pid"; then echo "$s: running (pid $(cat "$RUN/$s.pid"))"; else echo "$s: stopped"; fi
  done
  [ -f "$H/conf/docroot" ] && echo "docroot: $(cat "$H/conf/docroot")"
  echo "web: http://localhost:$PORT"
  echo "db: 127.0.0.1:$DB_PORT or $SOCK (user dev, password dev; database wordpress)"
}

start() {
  docroot=$(cd "$1" && pwd)
  write_config "$docroot"
  start_db
  start_web
  status
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
}

# By default the links go in Leopard's own folder, so nothing on the system
# changes. A system folder gets links only when the user names it.
link() {
  dir=${1:-$H/bin}
  mkdir -p "$dir"
  for t in leopard php wp composer mysql; do
    src=$R/bin/$t
    [ "$t" = leopard ] && src=$R/leopard
    # Replace our own older links, but never a php or mysql the system installed.
    if [ -e "$dir/$t" ] && ! readlink "$dir/$t" | grep -q '/runtime/'; then
      echo "Skipped $dir/$t: it already exists and is not from Leopard." >&2
      continue
    fi
    ln -sfn "$src" "$dir/$t"
  done
  echo "Linked leopard, php, wp, composer, and mysql in $dir."
  case :$PATH: in
    *":$dir:"*) ;;
    *) echo "To use them in this shell: export PATH=\"$dir:\$PATH\"" ;;
  esac
}

# php.ini holds absolute paths into this bundle, so every command rewrites it.
write_php_ini
cmd=${1:-help}
[ $# -gt 0 ] && shift
case $cmd in
  start) lock && start "${1:-.}" ;;
  stop) lock && stop_all ;;
  restart)
    lock
    docroot=$(cat "$H/conf/docroot" 2>/dev/null || pwd)
    stop_all
    start "$docroot"
    ;;
  status) status ;;
  php | wp | composer) exec "$cmd" "$@" ;;
  mysql) exec mariadb "$@" ;;
  logs) exec tail -n 50 -f "$H"/logs/*.log ;;
  link) link "${1:-}" ;;
  # A child shell with the bundled tools first on PATH. Exit it to go back.
  shell)
    echo "Leopard shell: php, wp, composer, mysql, and leopard are on PATH. Type exit to leave." >&2
    LEOPARD_SHELL=1 exec "${SHELL:-/bin/sh}" "$@"
    ;;
  version) cat "$R/VERSIONS" ;;
  *)
    cat <<EOF
Usage: leopard <command>
  start [docroot]   Start Apache + PHP + MariaDB, serving docroot (default: current dir)
  stop | restart | status | logs | version
  php ...           Bundled PHP CLI
  mysql ...         MariaDB client, logged in as root
  wp ...            WP-CLI
  composer ...      Composer
  shell             Open a shell with php, wp, composer, mysql, and leopard on PATH
  link [dir]        Link leopard, php, wp, composer, and mysql into dir
                    (default: $LEOPARD_HOME/bin, which changes nothing on the system)
Settings (env): LEOPARD_HOME (~/.leopard), LEOPARD_PORT (8080), LEOPARD_DB_PORT (3306)
EOF
    ;;
esac
