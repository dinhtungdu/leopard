#!/bin/bash
# Builds Leopard. Needs Docker on the build machine only. The output matches
# the build machine's CPU arch; build on x86_64 for x86_64.
#
#   ./build.sh                 packs for $PHP_VERSIONS (default 8.3), the core,
#                              and one file per PHP version
#   ./build.sh pack php 8.4    one PHP pack     -> dist/packs/
#   ./build.sh pack mysql 8.4  one MySQL pack   -> dist/packs/
#   ./build.sh core            the core         -> dist/core-linux-<arch>.tgz
#   ./build.sh files           dist/leopard-php<v>-linux-<arch> for every PHP
#                              pack in dist/packs, with that PHP inside
#
# A file holds the core and one PHP pack. It also lists every pack in
# dist/packs with its sha256, so `leopard php install` can fetch and check
# the others. LEOPARD_RELEASE (a tag such as v0.2.0) sets where they are
# fetched from.
set -euo pipefail
cd "$(dirname "$0")"
ARCH=$(uname -m)
mkdir -p dist/packs

pack() { # kind version
  out=dist/packs/$1-$2-linux-$ARCH.tgz
  case $1 in
    php) image=php:$2-fpm script=stage-php.sh ;;
    mysql) image=mysql:$2 script=stage-mysql.sh ;;
    *) echo "Unknown pack kind: $1" >&2; exit 2 ;;
  esac
  docker run --rm -v "$PWD:/src:ro" -v "$PWD/dist/packs:/dist" "$image" bash -c "
    set -e
    bash /src/$script /tmp/out
    tar -C /tmp/out -czf /dist/.tmp-$1-$2.tgz .
    chown $(id -u):$(id -g) /dist/.tmp-$1-$2.tgz"
  mv "dist/packs/.tmp-$1-$2.tgz" "$out"
  ls -lh "$out"
}

core() {
  docker run --rm -v "$PWD:/src:ro" -v "$PWD/dist:/dist" debian:trixie-slim bash -c "
    set -e
    bash /src/stage-core.sh /tmp/out
    tar -C /tmp/out -czf /dist/.core.tgz .
    chown $(id -u):$(id -g) /dist/.core.tgz"
  mv dist/.core.tgz "dist/core-linux-$ARCH.tgz"
}

# Prints the JSON index of every pack in dist/packs for this arch.
index() {
  base=${LEOPARD_PACKS_URL:-}
  [ -z "$base" ] && [ -n "${LEOPARD_RELEASE:-}" ] && base=https://github.com/dinhtungdu/leopard/releases/download/$LEOPARD_RELEASE/
  printf '{"format": 1, "release": "%s", "base_url": "%s", "packs": [' "${LEOPARD_RELEASE:-dev}" "$base"
  sep=''
  for f in dist/packs/*-linux-"$ARCH".tgz; do
    meta=$(tar -xzOf "$f" ./pack.json)
    sha=$(sha256sum "$f" | cut -d' ' -f1)
    kind=$(printf %s "$meta" | sed 's/.*"kind": "\([^"]*\)".*/\1/')
    ver=$(printf %s "$meta" | sed 's/.*"version": "\([^"]*\)".*/\1/')
    line=$(printf %s "$meta" | sed 's/.*"line": "\([^"]*\)".*/\1/')
    printf '%s\n  {"id": "%s", "kind": "%s", "version": "%s", "line": "%s", "file": "%s", "sha256": "%s", "size": %s}' \
      "$sep" "$kind-$ver-${sha:0:12}" "$kind" "$ver" "$line" "$(basename "$f")" "$sha" "$(stat -c %s "$f")"
    sep=,
  done
  printf '\n]}\n'
}

files() {
  idx=$(index)
  for f in dist/packs/php-*-linux-"$ARCH".tgz; do
    line=$(tar -xzOf "$f" ./pack.json | sed 's/.*"line": "\([^"]*\)".*/\1/')
    ver=$(tar -xzOf "$f" ./pack.json | sed 's/.*"version": "\([^"]*\)".*/\1/')
    id=php-$ver-$(sha256sum "$f" | cut -c1-12)
    t=$(mktemp -d)
    tar -xzf "dist/core-linux-$ARCH.tgz" -C "$t"
    mkdir -p "$t/packs/$id"
    tar -xzf "$f" -C "$t/packs/$id"
    cp leopard.sh "$t/leopard"
    cp leopard.php skills/using-leopard/SKILL.md "$t/"
    printf '%s\n' "$idx" >"$t/packs.json"
    echo "$id" >"$t/CONTROL_PHP"
    tar -C "$t" -czf dist/payload.tgz .
    rm -rf "$t"
    assemble "dist/leopard-php$line-linux-$ARCH"
  done
}

assemble() {
  ID=$(sha256sum dist/payload.tgz | cut -c1-12)
  header=$(cat <<'EOF'
#!/bin/sh
# Leopard: Apache + PHP + MariaDB in one file. Run: ./leopard help
set -e
# Root's home is closed to the web server's workers, so root keeps state in /var/lib.
if [ -z "${LEOPARD_HOME:-}" ]; then
  if [ "$(id -u)" = 0 ]; then LEOPARD_HOME=/var/lib/leopard; else LEOPARD_HOME=${HOME:-/tmp}/.leopard; fi
fi
case $LEOPARD_HOME in /*) ;; *) LEOPARD_HOME=$PWD/$LEOPARD_HOME ;; esac
export LEOPARD_HOME
# Where this file lives, so `leopard service install` can point systemd at it.
LEOPARD_BIN=$(cd "$(dirname "$0")" && pwd)/$(basename "$0")
export LEOPARD_BIN
R=$LEOPARD_HOME/runtime/@ID@
if [ ! -x "$R/leopard" ]; then
  mkdir -p "$LEOPARD_HOME/runtime" "$LEOPARD_HOME/packs"
  exec 9>"$LEOPARD_HOME/runtime/.lock"
  # Wait for another unpack, but not forever. Busybox flock has no -w.
  W=${LEOPARD_LOCK_WAIT:-120}
  case $W in '' | *[!0-9]*) W=120 ;; esac
  i=0
  while command -v flock >/dev/null && ! flock -n 9; do
    i=$((i + 1))
    if [ $i -gt $((W * 10)) ]; then
      m="Another Leopard file is still unpacking into $LEOPARD_HOME/runtime after ${W}s."
      case " $* " in
        *" --json "*) printf '{"ok":false,"api":1,"error":{"code":"busy","message":"%s","hint":"Retry, or set LEOPARD_LOCK_WAIT to wait longer."}}\n' "$m" ;;
        *) echo "leopard: $m" >&2 ;;
      esac
      exit 6
    fi
    sleep 0.1
  done
  if [ ! -x "$R/leopard" ]; then
    rm -rf "$LEOPARD_HOME"/runtime/.tmp.*
    T=$(mktemp -d "$LEOPARD_HOME/runtime/.tmp.XXXXXX")
    tail -n +@LINE@ "$0" | tar -xzf - -C "$T"
    # Packs are shared by every Leopard file in this home. Keep one copy.
    for p in "$T"/packs/*; do
      [ -e "$LEOPARD_HOME/packs/${p##*/}" ] || mv "$p" "$LEOPARD_HOME/packs/"
    done
    rm -rf "$T/packs" "$R"
    mv "$T" "$R"
  fi
  exec 9>&-
fi
exec "$R/leopard" "$@"
EOF
)
  lines=$(($(printf '%s\n' "$header" | wc -l) + 1))
  { printf '%s\n' "$header" | sed "s/@ID@/$ID/; s/@LINE@/$lines/"; cat dist/payload.tgz; } >"$1"
  chmod +x "$1"
  rm dist/payload.tgz
  ls -lh "$1"
}

case ${1:-all} in
  pack) pack "$2" "$3" ;;
  core) core ;;
  files) files ;;
  all)
    for v in ${PHP_VERSIONS:-${PHP_VERSION:-8.3}}; do
      [ -f "dist/packs/php-$v-linux-$ARCH.tgz" ] && [ -z "${REBUILD:-}" ] || pack php "$v"
    done
    [ -f "dist/core-linux-$ARCH.tgz" ] && [ -z "${REBUILD:-}" ] || core
    files
    ;;
  *) echo "Usage: ./build.sh [pack KIND VERSION | core | files]" >&2; exit 2 ;;
esac
