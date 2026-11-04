#!/bin/bash
# Runs inside a throwaway php:<version>-fpm container (Debian). Adds the PHP
# extensions, then writes a PHP pack to $1: php, php-fpm, the extensions,
# every shared library they need, and the glibc loader. A pack runs on any
# Linux kernel of the same CPU arch and needs nothing from the host.
set -euo pipefail
OUT=$1
export DEBIAN_FRONTEND=noninteractive

apt-get update -qq
apt-get install -y -qq --no-install-recommends \
  libpng-dev libjpeg62-turbo-dev libfreetype-dev libwebp-dev libicu-dev libzip-dev \
  libxslt1-dev libffi-dev libmagickwand-dev ca-certificates >/dev/null
docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp >/dev/null
docker-php-ext-configure ffi --with-ffi >/dev/null
docker-php-ext-install -j"$(nproc)" gd intl mysqli pdo_mysql zip bcmath exif \
  calendar ftp gettext pcntl shmop sockets sysvmsg sysvsem sysvshm xsl ffi >/dev/null
for ext in xdebug imagick; do
  # imagick asks for the ImageMagick prefix; an empty answer means autodetect.
  echo | MAKEFLAGS=-j$(nproc) pecl install "$ext" >/tmp/pecl-$ext.log 2>&1 || { tail -30 /tmp/pecl-$ext.log; exit 1; }
done
docker-php-ext-enable xdebug imagick >/dev/null
# Upstream PHP 8.5+ builds opcache in; older versions need it installed.
php -m | grep -q 'Zend OPcache' || docker-php-ext-install opcache >/dev/null

mkdir -p "$OUT"/{lib,libexec,bin,ext}
cp /usr/local/bin/php /usr/local/sbin/php-fpm "$OUT/libexec/"
cp "$(php -r 'echo ini_get("extension_dir");')"/*.so "$OUT/ext/"
# Every extension except Xdebug. Leopard adds Xdebug only where a site wants it.
cat /usr/local/etc/php/conf.d/*.ini | grep -E '^(zend_)?extension' | grep -v xdebug >"$OUT/php.ini"
cp /etc/ssl/certs/ca-certificates.crt "$OUT/"
# ImageMagick loads its file-format coders and its config from compiled-in paths.
mkdir -p "$OUT/imagemagick"
cp -r "$(find /usr/lib -maxdepth 2 -type d -name 'ImageMagick-*' | head -1)/." "$OUT/imagemagick/lib/"
cp -r "$(find /etc -maxdepth 1 -type d -name 'ImageMagick-*' | head -1)/." "$OUT/imagemagick/etc/"

# Harvest the shared library closure of every ELF file we ship.
find "$OUT/libexec" "$OUT/ext" "$OUT/imagemagick" -type f \
  -exec sh -c 'head -c4 "$1" | grep -q ELF && ldd "$1"' _ {} \; >/tmp/ldd.txt
if grep 'not found' /tmp/ldd.txt; then echo 'Missing libraries above' >&2; exit 1; fi
awk '/=> \//{print $3} /^[[:space:]]*\/.*ld-linux/{print $1}' /tmp/ldd.txt | sort -u |
  while read -r so; do cp -Ln "$so" "$OUT/lib/"; done
# ldd misses libraries that glibc only dlopens: NSS and resolver.
cp -Ln /lib/*-linux-gnu/libnss_*.so.2 /lib/*-linux-gnu/libresolv.so.2 "$OUT/lib/" 2>/dev/null || true
# glibc loads charset converters (used by iconv) from a compiled-in path.
cp -r /usr/lib/*-linux-gnu/gconv "$OUT/lib/gconv"
LDSO=$(basename "$(ls "$OUT"/lib/ld-linux-*.so.*)")

# Each wrapper runs its binary through the pack's own loader, so the host's
# glibc never matters. The pack lives at <home>/packs/<id>, so <home> is two
# levels up. `php` reads the ini that Leopard writes for this pack, or the
# pack's own php.ini (extensions only) before Leopard has written one.
# `php-fpm` gets its ini from Leopard with -c.
for b in php php-fpm; do
  ini=''
  # shellcheck disable=SC2016  # Expanded by the wrapper, not here.
  [ "$b" = php ] && ini='
ini=$H/conf/php/cli-${P##*/}.ini
[ -f "$ini" ] || ini=$P/php.ini
set -- -c "$ini" "$@"'
  cat >"$OUT/bin/$b" <<EOF
#!/bin/sh
P=\$(cd "\$(dirname "\$(readlink -f "\$0")")/.." && pwd)
H=\$(cd "\$P/../.." && pwd)
M=\$P/imagemagick
export MAGICK_CODER_MODULE_PATH="\$(echo "\$M"/lib/modules-*/coders)" MAGICK_CODER_FILTER_PATH="\$(echo "\$M"/lib/modules-*/filters)" MAGICK_CONFIGURE_PATH="\$M/etc"
# An empty scan dir keeps a PHP install on the host out of the way.
export GCONV_PATH="\$P/lib/gconv" PHP_INI_SCAN_DIR="\${PHP_INI_SCAN_DIR-}"$ini
exec "\$P/lib/$LDSO" --library-path "\$P/lib" --argv0 "\$0" "\$P/libexec/$b" -d extension_dir="\$P/ext" \\
  -d openssl.cafile="\$P/ca-certificates.crt" -d curl.cainfo="\$P/ca-certificates.crt" "\$@"
EOF
  chmod +x "$OUT/bin/$b"
done

v=$(php -r 'echo PHP_VERSION;')
cat >"$OUT/pack.json" <<EOF
{"format": 1, "kind": "php", "version": "$v", "line": "${v%.*}", "arch": "$(uname -m)", "xdebug": "$(php -r 'echo phpversion("xdebug");')"}
EOF
