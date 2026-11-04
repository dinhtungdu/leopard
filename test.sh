#!/bin/bash
# Proves a built bundle works on bare Linux images that have no PHP, Apache,
# MariaDB, or (on Alpine) glibc. Usage: ./test.sh dist/leopard-...
# Docker is only the test harness here; the bundle itself never needs it.
set -euo pipefail
BIN=$(realpath "$1")
SITE=$(mktemp -d)
c=
trap '[ -n "$c" ] && docker rm -f "$c" >/dev/null 2>&1; rm -rf "$SITE"' EXIT
cat >"$SITE/index.php" <<'EOF'
<?php
$db = new mysqli('localhost', 'dev', 'dev', 'wordpress');
$missing = array_filter(['mysqli', 'pdo_mysql', 'gd', 'intl', 'mbstring', 'zip', 'curl', 'dom', 'exif', 'bcmath', 'Zend OPcache'], fn($e) => !extension_loaded($e));
echo 'OK sapi=', PHP_SAPI, ' iconv=', var_export(iconv('UTF-8', 'ISO-8859-1', 'é') === "\xE9", true), ' uid=', posix_geteuid(), ' php=', PHP_VERSION, ' db=', $db->server_info, ' missing=[', implode(',', $missing), "]\n";
EOF
ms() { echo $(( ($(date +%s%N) - $1) / 1000000 )); }
get='leopard php -r "echo file_get_contents(\"http://127.0.0.1:8080/\");"'

for image in alpine:3.20 debian:12-slim ubuntu:24.04; do
  echo "== $image"
  c=$(docker run -d -v "$BIN:/usr/local/bin/leopard:ro" "$image" sleep infinity)
  docker exec -i "$c" sh -c 'mkdir /site && cat >/site/index.php' <"$SITE/index.php"
  t=$(date +%s%N); docker exec "$c" leopard start /site >/dev/null; echo "cold start (unpack + db + web): $(ms "$t") ms"
  docker exec "$c" sh -c "$get" | grep '^OK' | grep 'sapi=apache2handler iconv=true uid=65534' | grep 'missing=\[\]'
  docker exec "$c" leopard start /site >/dev/null && echo "second start while running: ok"
  docker exec "$c" leopard stop
  # A stale pid file that points at a live, unrelated process (PID 1).
  docker exec "$c" sh -c 'echo 1 >/var/lib/leopard/run/mariadb.pid; echo 1 >/var/lib/leopard/run/apache.pid'
  t=$(date +%s%N); docker exec "$c" leopard start /site >/dev/null; echo "warm start: $(ms "$t") ms"
  docker exec "$c" sh -c "$get" | grep -q '^OK' && echo "serves after restart: ok"
  docker rm -f "$c" >/dev/null
done

echo "== WordPress on alpine:3.20"
c=$(docker run -d -v "$BIN:/dl/leopard-download:ro" alpine:3.20 sleep infinity)
docker exec "$c" sh -ec '
  # Like a fresh machine: link the commands inside Leopard'"'"'s own folder only.
  /dl/leopard-download link
  export PATH=/var/lib/leopard/bin:$PATH
  [ ! -e /usr/local/bin/php ] && echo "system untouched: ok"
  mkdir /wp && cd /wp
  # Two first starts at once must not corrupt the database copy.
  leopard start /wp >/dev/null & leopard start /wp >/dev/null; wait
  printf "type php wp composer mysql mysqladmin mysqldump leopard\\n" | SHELL=/bin/sh leopard shell 2>/dev/null | grep -c /var/lib/leopard/runtime/ | grep -qx 7
  echo "leopard shell: ok"
  # WooCommerce tests/bin/install.sh needs mysqladmin on PATH.
  mysqladmin -udev -pdev -h127.0.0.1 ping
  mysqldump -udev -pdev -h127.0.0.1 --no-data wordpress >/dev/null
  echo "mysqldump: ok"
  wp core download --quiet
  wp config create --dbname=wordpress --dbuser=dev --dbpass=dev --dbhost=127.0.0.1 --quiet
  chown -R 65534:65534 /wp
  wp core install --url=http://127.0.0.1:8080 --title=LeopardCheck --admin_user=admin --admin_password=admin --admin_email=a@example.com --skip-email
  php -r "echo file_get_contents(\"http://127.0.0.1:8080/\");" | grep -o "<title>.*</title>"
  mysql -N -e "select option_value from wordpress.wp_options where option_name=\"blogname\""
  wp db query "select count(*) from wp_posts" --skip-column-names
  composer --version 2>/dev/null
'
docker rm -f "$c" >/dev/null
