#!/bin/sh
# Runtime launcher. Lives at the root of the unpacked bundle as ./leopard.
# All state (database, config, sites, logs, pids) lives in $LEOPARD_HOME.
# The tool shortcuts (php, wp, composer, mysql, shell) and the commands that
# only touch files (link, service, skill show) run here. Everything else runs in
# leopard.php on the PHP that this Leopard file came with.
# Exit codes: 0 ok, 1 error, 2 bad usage, 3 not found, 4 already exists,
# 5 server not running, 6 busy.
set -eu
R=$(cd "$(dirname "$(readlink -f "$0")")" && pwd)
# Run through the single file, LEOPARD_HOME is set. Run through a symlink made
# by `leopard link`, the home is where this runtime was unpacked: <home>/runtime/<id>.
H=${LEOPARD_HOME:-$(cd "$R/../.." && pwd)}
export LEOPARD_HOME=$H LP_RUNTIME=$R
# Unix socket paths are limited to about 107 bytes.
LP_SOCK=$H/run/mysqld.sock
[ ${#LP_SOCK} -gt 100 ] && LP_SOCK=/tmp/leopard-$(printf %s "$H" | cksum | cut -d' ' -f1).sock
export LP_SOCK
export PATH="$R/bin:$PATH"
CP=$H/packs/$(cat "$R/CONTROL_PHP")

# The control plane. It runs with Xdebug off; the caller's XDEBUG_MODE is
# kept for the tools it starts.
control() {
  [ -x "$CP/bin/php" ] || { echo "leopard: $CP is missing. Delete $R and run the Leopard file again." >&2; exit 1; }
  [ -n "${XDEBUG_MODE+x}" ] && export LP_XDEBUG_MODE="$XDEBUG_MODE"
  XDEBUG_MODE=off exec "$CP/bin/php" "$R/leopard.php" "$@"
}

die() { echo "leopard: $2" >&2; exit "$1"; }

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

# Start at boot with systemd. Only on request: with `skill setup`, it is one of
# two commands that write outside $LEOPARD_HOME (a systemd unit file). With --run, systemd
# runs `leopard run` and restarts it if it fails.
service() {
  command -v systemctl >/dev/null || die 1 "systemd is not available here."
  BIN=${LEOPARD_BIN:-$R/leopard}
  if [ "$(id -u)" = 0 ]; then
    unit=/etc/systemd/system/leopard.service sc=systemctl target=multi-user.target
  else
    unit=${XDG_CONFIG_HOME:-$HOME/.config}/systemd/user/leopard.service sc="systemctl --user" target=default.target
  fi
  case ${1:-status} in
    install)
      if [ "${2:-}" = --run ]; then
        svc="Type=simple
ExecStart=$BIN run
Restart=on-failure"
      else
        svc="Type=oneshot
RemainAfterExit=yes
ExecStart=$BIN start
ExecStop=$BIN stop"
      fi
      mkdir -p "$(dirname "$unit")"
      cat >"$unit" <<EOF
[Unit]
Description=Leopard (Apache, PHP, MariaDB) for $H
After=network-online.target

[Service]
$svc
Environment=LEOPARD_HOME=$H

[Install]
WantedBy=$target
EOF
      $sc daemon-reload
      $sc enable leopard.service
      echo "Installed $unit."
      if [ "$(id -u)" != 0 ] && [ "$(loginctl show-user "$(id -un)" -p Linger --value 2>/dev/null)" != yes ]; then
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
    *) die 2 "Usage: leopard service install [--run] | uninstall | status" ;;
  esac
}

usage() {
  cat <<EOF
Usage: leopard <command> [--json]

Server
  start [docroot]     Start Apache, PHP, and the database. Serves docroot at /
                      (default: the last docroot, else \$LEOPARD_HOME/www, which
                      lists your sites). Safe to rerun.
  stop | restart
  run                 Start, then stay in the foreground and restart anything that
                      dies. For containers and systemd. Ctrl-C or SIGTERM stops all.
  status              Ports, URLs, database login, sites, and what runs
  doctor              Check the servers and every site; exits 1 if a check fails
  logs [--site NAME] [--kind php,access,fpm,web,db] [--since 10m|ISO|CURSOR]
       [--wait SECONDS] [-n N] [-f]
  logs mark           A cursor for "everything after now"
  xdebug [MODE]       Show or set the default Xdebug mode: off, debug, coverage, ...
  config [set KEY V]  Show settings, or save port, db_port, or url (the base URL
                      that browsers use, like http://100.1.2.3:8080)
  service install [--run] | uninstall | status
                      Start at boot with systemd. Writes outside \$LEOPARD_HOME.

Sites (WordPress, served at <url>/<name>)
  site create [NAME] [--php VERSION] [--xdebug MODE] [--dir PATH] [--host[=HOST:PORT]]
                   [--plugin [NAME=]PATH]... [--activate PLUGIN]... [--theme [NAME=]PATH]
                   [--import-sql FILE [--content ZIP] [--from-url URL]]
                   [--wp-version V] [--title T] [--admin-user U] [--admin-password P]
                   Without NAME, inside a plugin or theme folder: names the site
                   after the folder, links it, and activates it. Starts the stack.
                   --host gives the site a host of its own instead of <url>/NAME:
                   alone, the base host and a free port; or --host=localhost:8090.
  site set NAME [--php VERSION|default] [--xdebug MODE|default]
  site list | site info NAME | site path NAME | site delete NAME
  site wp NAME ARGS...   Run WP-CLI against that site, with its PHP
  site php NAME ARGS...  Run that site's PHP

PHP and database versions
  php list [--available] | php install VERSION | php default VERSION
  db list [--available] | db use ENGINE [--copy-data] | db prune ENGINE
  packs list | packs add FILE | packs gc [--dry-run]

Tools (bundled)
  php | wp | composer | mysql ...   mysql logs in as root
  shell [-c CMD]      A shell with php, wp, composer, mysql, mysqladmin, mysqldump,
                      and leopard first on PATH
  link [dir]          Link those tools into dir (default: \$LEOPARD_HOME/bin)
  version             Versions of Apache, PHP, and the database
  update [TAG]        Download the latest Leopard (or release TAG) and install it
                      over this one, keeping its PHP. Restarts it if it runs.
  skill [show]        Print the guide for AI agents (SKILL.md)
  skill setup [--dry-run] [--force] | status | remove
                      Install a small skill into ~/.agents/skills (and
                      ~/.claude/skills) that reads the guide from this Leopard.
                      Writes outside \$LEOPARD_HOME, like service install.

Settings come from the environment first, then from earlier runs:
  LEOPARD_HOME ($H)
  LEOPARD_PORT, LEOPARD_DB_PORT, LEOPARD_URL: saved by a successful start
  LEOPARD_LOCK_WAIT (120): seconds to wait for another command before exit 6
Exit codes: 0 ok, 1 error, 2 bad usage, 3 not found, 4 already exists, 5 not running, 6 busy.
EOF
}

cmd=${1:-help}
[ $# -gt 0 ] && shift
# `leopard php list|install|default` manage PHP versions. Any other
# `leopard php ...`, or a script with one of those names, runs PHP itself.
if [ "$cmd" = php ]; then
  case ${1:-} in list | install | default) [ -e "$1" ] || control php "$@" ;; esac
fi
case $cmd in
  # php.ini and the other generated settings must exist before the first tool runs.
  php | wp | composer | mysql | shell) [ -f "$H/conf/wp-cli.yml" ] || (control prepare) ;;
esac
case $cmd in
  php | wp | composer) exec "$R/bin/$cmd" "$@" ;;
  mysql) exec "$R/bin/mariadb" "$@" ;;
  # A child shell with the bundled tools first on PATH. Exit it to go back.
  shell)
    echo "Leopard shell: php, wp, composer, mysql, mysqladmin, mysqldump, and leopard are on PATH. Type exit to leave." >&2
    LEOPARD_SHELL=1 exec "${SHELL:-/bin/sh}" "$@"
    ;;
  # Linked tools run without the launcher, so write their settings now.
  link) (control prepare); link "${1:-}" ;;
  service) service "$@" ;;
  update)
    case $#:${1:-} in 0: | 1:[!-]*) ;; *) die 2 "Usage: leopard update [TAG]" ;; esac
    [ $# -eq 1 ] && export LEOPARD_VERSION="$1"
    # The installer defaults to PHP 8.3; keep the PHP line this file came with.
    LEOPARD_PHP=${LEOPARD_PHP:-$(sed 's/^php-\([0-9]*\.[0-9]*\).*/\1/' "$R/CONTROL_PHP")}
    export LEOPARD_PHP
    exec sh "$R/install.sh"
    ;;
  skill) case ${1:-show} in show) cat "$R/SKILL.md" ;; *) control skill "$@" ;; esac ;;
  help | -h | --help) usage ;;
  *) control "$cmd" "$@" ;;
esac
