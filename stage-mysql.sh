#!/bin/bash
# Runs inside a throwaway mysql:<version> container (Oracle Linux). Writes a
# MySQL pack to $1: mysqld, its plugins, every shared library they need, and
# the glibc loader. Leopard creates the data folder on first use, so the pack
# has no seed. The clients that Leopard ships (mysql, mysqldump) are
# MariaDB's, and they work with MySQL too.
set -euo pipefail
OUT=$1
mkdir -p "$OUT"/{bin,libexec,lib,share,plugin}
cp "$(command -v mysqld)" "$OUT/libexec/"
cp -r /usr/share/mysql*/. "$OUT/share/"
cp -r "$(dirname "$(find / -name 'component_*.so' -not -path '/proc/*' 2>/dev/null | head -1)")/." "$OUT/plugin/"
# mysqld looks for its ICU regex data at <basedir>/lib64/mysql/private.
if [ -d /usr/lib64/mysql/private ]; then
  mkdir -p "$OUT/lib64/mysql"
  cp -r /usr/lib64/mysql/private "$OUT/lib64/mysql/"
fi
LD_LIBRARY_PATH=$(dirname "$(find / -name 'libabsl_base.so*' -not -path '/proc/*' 2>/dev/null | head -1)")
export LD_LIBRARY_PATH
for f in "$OUT"/libexec/* "$OUT"/plugin/*.so; do ldd "$f" 2>/dev/null || true; done >/tmp/ldd.txt
if grep 'not found' /tmp/ldd.txt; then echo 'Missing libraries above' >&2; exit 1; fi
awk '/=> \//{print $3} /^[[:space:]]*\/.*ld-linux/{print $1}' /tmp/ldd.txt | sort -u |
  while read -r so; do cp -Ln "$so" "$OUT/lib/"; done
cp -Ln /lib64/libnss_*.so.2 /lib64/libresolv.so.2 "$OUT/lib/" 2>/dev/null || true
LDSO=$(basename "$(ls "$OUT"/lib/ld-linux-*.so.*)")
cat >"$OUT/bin/mysqld" <<EOF
#!/bin/sh
P=\$(cd "\$(dirname "\$(readlink -f "\$0")")/.." && pwd)
exec "\$P/lib/$LDSO" --library-path "\$P/lib" --argv0 "\$0" "\$P/libexec/mysqld" "\$@"
EOF
chmod +x "$OUT/bin/mysqld"
v=$(mysqld --version | sed -n 's/.*Ver \([0-9.]*\).*/\1/p')
cat >"$OUT/pack.json" <<EOF
{"format": 1, "kind": "mysql", "version": "$v", "line": "${v%.*}", "arch": "$(uname -m)"}
EOF
