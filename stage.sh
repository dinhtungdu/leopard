#!/bin/bash
# Runs inside a throwaway php:<version>-apache container (Debian). Adds the
# PHP extensions and MariaDB, then copies the binaries, every shared library
# they need, and the glibc loader into $1. The result runs on any Linux kernel
# of the same CPU arch, with nothing installed on the host.
set -euo pipefail
OUT=$1
export DEBIAN_FRONTEND=noninteractive

apt-get update -qq
apt-get install -y -qq --no-install-recommends mariadb-server mariadb-client \
  libpng-dev libjpeg62-turbo-dev libfreetype-dev libwebp-dev libicu-dev libzip-dev \
  libxslt1-dev libffi-dev libmagickwand-dev ca-certificates curl media-types >/dev/null
docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp >/dev/null
docker-php-ext-configure ffi --with-ffi >/dev/null
docker-php-ext-install -j"$(nproc)" gd intl mysqli pdo_mysql zip bcmath exif \
  calendar ftp gettext pcntl shmop sockets sysvmsg sysvsem sysvshm xsl ffi >/dev/null
# Xdebug is always loaded. php.ini sets its mode, which is off unless the user
# turns it on with `leopard xdebug <mode>`.
for ext in xdebug imagick; do
  # imagick asks for the ImageMagick prefix; an empty answer means autodetect.
  echo | MAKEFLAGS=-j$(nproc) pecl install "$ext" >/tmp/pecl-$ext.log 2>&1 || { tail -30 /tmp/pecl-$ext.log; exit 1; }
done
docker-php-ext-enable xdebug imagick >/dev/null
# Upstream PHP 8.5+ builds opcache in; older versions need it installed.
php -m | grep -q 'Zend OPcache' || docker-php-ext-install opcache >/dev/null

mkdir -p "$OUT"/{lib,libexec,bin,modules,php/ext,php/conf.d,mariadb/plugin,mariadb/share,conf,seed}
cp /usr/sbin/apache2 /usr/sbin/mariadbd /usr/bin/mariadb /usr/bin/mariadb-dump /usr/bin/mariadb-admin "$OUT/libexec/"
cp /usr/local/bin/php "$OUT/libexec/"
cp /usr/lib/apache2/modules/*.so "$OUT/modules/"
cp "$(php -r 'echo ini_get("extension_dir");')"/*.so "$OUT/php/ext/"
cp /usr/local/etc/php/conf.d/*.ini "$OUT/php/conf.d/"
cp -r /usr/lib/mysql/plugin/. "$OUT/mariadb/plugin/"
share=$(dirname "$(find /usr/share/mariadb /usr/share/mysql -maxdepth 2 -name charsets -type d 2>/dev/null | head -1)")
cp -r "$share/." "$OUT/mariadb/share/"
cp /etc/mime.types /etc/ssl/certs/ca-certificates.crt "$OUT/conf/"
# ImageMagick loads its file-format coders and its config from compiled-in paths.
mkdir -p "$OUT/imagemagick"
cp -r "$(find /usr/lib -maxdepth 2 -type d -name 'ImageMagick-*' | head -1)/." "$OUT/imagemagick/lib/"
cp -r "$(find /etc -maxdepth 1 -type d -name 'ImageMagick-*' | head -1)/." "$OUT/imagemagick/etc/"
# The CLI writes errors to stderr, so warnings never mix into output that
# scripts read, such as `url=$(wp option get home)`.
mkdir -p "$OUT/php/cli.d"
cat >"$OUT/php/cli.d/zz-leopard-cli.ini" <<'EOF'
display_errors = stderr
error_reporting = E_ALL & ~E_DEPRECATED
EOF

# Harvest the shared library closure of every ELF file we ship.
find "$OUT/libexec" "$OUT/modules" "$OUT/php/ext" "$OUT/mariadb/plugin" "$OUT/imagemagick" -type f \
  -exec sh -c 'head -c4 "$1" | grep -q ELF && ldd "$1"' _ {} \; >/tmp/ldd.txt
if grep 'not found' /tmp/ldd.txt; then echo 'Missing libraries above' >&2; exit 1; fi
awk '/=> \//{print $3} /^\s*\/.*ld-linux/{print $1}' /tmp/ldd.txt | sort -u |
  while read -r so; do cp -Ln "$so" "$OUT/lib/"; done
# ldd misses libraries that glibc only dlopens: NSS and resolver.
cp -Ln /lib/*-linux-gnu/libnss_*.so.2 /lib/*-linux-gnu/libresolv.so.2 "$OUT/lib/" 2>/dev/null || true
# glibc loads charset converters (used by iconv) from a compiled-in path.
cp -r /usr/lib/*-linux-gnu/gconv "$OUT/lib/gconv"
LDSO=$(basename "$(ls "$OUT"/lib/ld-linux-*.so.*)")

# Every binary runs through the bundled loader, so the host's glibc never matters.
# Each wrapper finds its own config, so it works from any PATH, even as a symlink.
# The bundle unpacks to <home>/runtime/<id>, so <home> is two levels up.
for b in apache2 mariadbd mariadb mariadb-dump mariadb-admin php; do
  scan='$R/php/conf.d'
  [ "$b" = php ] && scan='$R/php/conf.d:$R/php/cli.d'
  cat >"$OUT/bin/$b" <<EOF
#!/bin/sh
R=\$(cd "\$(dirname "\$(readlink -f "\$0")")/.." && pwd)
H=\$(cd "\$R/../.." && pwd)
M=\$R/imagemagick
export MAGICK_CODER_MODULE_PATH="\$(echo "\$M"/lib/modules-*/coders)" MAGICK_CODER_FILTER_PATH="\$(echo "\$M"/lib/modules-*/filters)" MAGICK_CONFIGURE_PATH="\$M/etc"
PHPRC="\$H/conf" PHP_INI_SCAN_DIR="$scan" MYSQL_HOME="\$H/conf" GCONV_PATH="\$R/lib/gconv" exec "\$R/lib/$LDSO" --library-path "\$R/lib" --argv0 "\$0" "\$R/libexec/$b" "\$@"
EOF
  chmod +x "$OUT/bin/$b"
done
ln -s mariadb "$OUT/bin/mysql"
ln -s mariadb-dump "$OUT/bin/mysqldump"
ln -s mariadb-admin "$OUT/bin/mysqladmin"
# So `leopard` itself works inside `leopard shell`. build.sh adds ../leopard.
ln -s ../leopard "$OUT/bin/leopard"

# A database that is already initialized, with a wordpress db and a dev user,
# so first start copies files instead of bootstrapping MariaDB.
# Login: dev / dev, or root with no password.
mariadb-install-db --user=root --datadir=/tmp/seed --auth-root-authentication-method=normal --skip-test-db >/dev/null
mariadbd --user=root --datadir=/tmp/seed --skip-networking --socket=/tmp/seed.sock --pid-file=/tmp/seed.pid --skip-log-bin &
for _ in $(seq 50); do [ -S /tmp/seed.sock ] && break; sleep 0.2; done
mariadb -uroot --socket=/tmp/seed.sock <<'SQL'
CREATE DATABASE wordpress;
CREATE USER 'dev'@'%' IDENTIFIED BY 'dev';
CREATE USER 'dev'@'localhost' IDENTIFIED BY 'dev';
GRANT ALL ON *.* TO 'dev'@'%' WITH GRANT OPTION;
GRANT ALL ON *.* TO 'dev'@'localhost' WITH GRANT OPTION;
SQL
mariadb-admin -uroot --socket=/tmp/seed.sock shutdown
wait
mv /tmp/seed "$OUT/seed/mysql"

curl -fsSL -o "$OUT/wp-cli.phar" https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
curl -fsSL -o "$OUT/composer.phar" https://getcomposer.org/download/latest-stable/composer.phar
for tool in wp-cli composer; do
  cat >"$OUT/bin/${tool%-cli}" <<EOF
#!/bin/sh
R=\$(cd "\$(dirname "\$(readlink -f "\$0")")/.." && pwd)
# WP-CLI and Composer run php and mariadb from PATH, so put ours first.
export PATH="\$R/bin:\$PATH"
# Leopard's WP-CLI config knows about mod_rewrite and inherits the user's own.
H=\$(cd "\$R/../.." && pwd)
[ -f "\$H/conf/wp-cli.yml" ] && export WP_CLI_CONFIG_PATH="\${WP_CLI_CONFIG_PATH:-\$H/conf/wp-cli.yml}"
[ "\$(id -u)" = 0 ] && export WP_CLI_ALLOW_ROOT=1 COMPOSER_ALLOW_SUPERUSER=1
exec "\$R/bin/php" "\$R/$tool.phar" "\$@"
EOF
  chmod +x "$OUT/bin/${tool%-cli}"
done

cat >"$OUT/VERSIONS" <<EOF
apache $(apache2 -v | sed -n 's/.*Apache\/\([^ ]*\).*/\1/p')
php $(php -r 'echo PHP_VERSION;')
mariadb $(mariadbd --version | awk '{print $3}')
EOF
