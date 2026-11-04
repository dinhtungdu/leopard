#!/bin/bash
# Builds dist/leopard-linux-<arch>: one self-extracting file with Apache, PHP,
# MariaDB, WP-CLI, and Composer. Needs Docker on the build machine only.
# PHP_VERSION picks the PHP line (default 8.3).
# The output matches the build machine's CPU arch; build on x86_64 for x86_64.
set -euo pipefail
cd "$(dirname "$0")"
ARCH=$(uname -m)
PHP_VERSION=${PHP_VERSION:-8.3}
mkdir -p dist

docker run --rm -v "$PWD:/src:ro" -v "$PWD/dist:/dist" "php:$PHP_VERSION-apache" bash -c "
  set -e
  bash /src/stage.sh /tmp/out
  cp /src/leopard.sh /tmp/out/leopard
  cp /src/leopard.php /src/skills/using-leopard/SKILL.md /tmp/out/
  tar -C /tmp/out -czf /dist/payload.tgz .
  chown $(id -u):$(id -g) /dist/payload.tgz"

ID=$(sha256sum dist/payload.tgz | cut -c1-12)
header=$(cat <<'EOF'
#!/bin/sh
# Leopard: Apache + PHP + MariaDB in one file. Run: ./leopard help
set -e
# Root's home is closed to Apache's workers, so root keeps state in /var/lib.
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
  mkdir -p "$LEOPARD_HOME/runtime"
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
    rm -rf "$R"
    mv "$T" "$R"
  fi
  exec 9>&-
fi
exec "$R/leopard" "$@"
EOF
)
lines=$(($(printf '%s\n' "$header" | wc -l) + 1))
out=dist/leopard-php$PHP_VERSION-linux-$ARCH
{ printf '%s\n' "$header" | sed "s/@ID@/$ID/; s/@LINE@/$lines/"; cat dist/payload.tgz; } >"$out"
chmod +x "$out"
rm dist/payload.tgz
ls -lh "$out"
