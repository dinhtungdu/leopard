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
R=$LEOPARD_HOME/runtime/@ID@
if [ ! -x "$R/leopard" ]; then
  mkdir -p "$LEOPARD_HOME/runtime"
  exec 9>"$LEOPARD_HOME/runtime/.lock"
  command -v flock >/dev/null && flock 9
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
