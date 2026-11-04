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
$missing = array_filter(['mysqli', 'pdo_mysql', 'gd', 'intl', 'mbstring', 'zip', 'curl', 'dom', 'exif', 'bcmath', 'Zend OPcache',
  'xdebug', 'imagick', 'calendar', 'ftp', 'gettext', 'pcntl', 'shmop', 'sockets', 'sysvmsg', 'sysvsem', 'sysvshm', 'xsl', 'FFI'], fn($e) => !extension_loaded($e));
// ImageMagick loads its format coders at run time from the bundle.
$im = new Imagick();
$im->newImage(8, 8, 'red', 'png');
$png = $im->getImageBlob();
$im = new Imagick();
$im->readImageBlob($png);
$im->setImageFormat('webp');
$imagick = strlen($im->getImageBlob()) > 0 ? 'ok' : 'broken';
echo 'OK sapi=', PHP_SAPI, ' imagick=', $imagick, ' xdebug=', ini_get('xdebug.mode'), ' iconv=', var_export(iconv('UTF-8', 'ISO-8859-1', 'é') === "\xE9", true), ' uid=', posix_geteuid(), ' php=', PHP_VERSION, ' db=', $db->server_info, ' missing=[', implode(',', $missing), "]\n";
EOF
ms() { echo $(( ($(date +%s%N) - $1) / 1000000 )); }
get='leopard php -r "echo file_get_contents(\"http://127.0.0.1:8080/\");"'

for image in alpine:3.20 debian:12-slim ubuntu:24.04; do
  echo "== $image"
  c=$(docker run -d -v "$BIN:/usr/local/bin/leopard:ro" "$image" sleep infinity)
  docker exec -i "$c" sh -c 'mkdir /site && cat >/site/index.php' <"$SITE/index.php"
  t=$(date +%s%N); docker exec "$c" leopard start /site >/dev/null; echo "cold start (unpack + db + web): $(ms "$t") ms"
  docker exec "$c" sh -c "$get" | grep '^OK' | grep 'sapi=apache2handler imagick=ok xdebug=off iconv=true uid=65534' | grep 'missing=\[\]'
  docker exec "$c" leopard start /site >/dev/null && echo "second start while running: ok"
  docker exec "$c" leopard stop
  # A stale pid file that points at a live, unrelated process (PID 1).
  docker exec "$c" sh -c 'echo 1 >/var/lib/leopard/run/mariadb.pid; echo 1 >/var/lib/leopard/run/apache.pid'
  t=$(date +%s%N); docker exec "$c" leopard start /site >/dev/null; echo "warm start: $(ms "$t") ms"
  docker exec "$c" sh -c "$get" | grep -q '^OK' && echo "serves after restart: ok"
  docker rm -f "$c" >/dev/null
done

echo "== WordPress on alpine:3.20"
# Without network access to wordpress.org, the host's WP-CLI cache lets
# `site create` and `wp core download` still find WordPress.
cache=()
[ -d "$HOME/.wp-cli/cache/core" ] && cache=(-v "$HOME/.wp-cli/cache/core:/root/.wp-cli/cache/core:ro")
c=$(docker run -d -v "$BIN:/dl/leopard-download:ro" "${cache[@]}" alpine:3.20 sleep infinity)
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
  wp core download --quiet --version="$(ls /root/.wp-cli/cache/core 2>/dev/null | sed -n "s/wordpress-\\(.*\\)-en_US.tar.gz/\\1/p" | sort -V | tail -1)" 2>/dev/null || wp core download --quiet
  wp config create --dbname=wordpress --dbuser=dev --dbpass=dev --dbhost=127.0.0.1 --quiet
  chown -R 65534:65534 /wp
  wp core install --url=http://127.0.0.1:8080 --title=LeopardCheck --admin_user=admin --admin_password=admin --admin_email=a@example.com --skip-email
  php -r "echo file_get_contents(\"http://127.0.0.1:8080/\");" | grep -o "<title>.*</title>"
  mysql -N -e "select option_value from wordpress.wp_options where option_name=\"blogname\""
  wp db query "select count(*) from wp_posts" --skip-column-names
  composer --version 2>/dev/null
'
docker rm -f "$c" >/dev/null

echo "== Agent contract on alpine:3.20"
c=$(docker run -d -v "$BIN:/usr/local/bin/leopard:ro" "${cache[@]}" alpine:3.20 sleep infinity)
docker exec "$c" sh -ec '
  j() { leopard php -r "\$d = json_decode(stream_get_contents(STDIN), true); echo $1, PHP_EOL;"; }
  mkdir /www
  LEOPARD_URL=http://127.0.0.1:8080 leopard start /www --json | j "\$d[\"web\"][\"running\"] ? \"start --json: ok\" : \"bad\""
  # Settings persist: no env needed now, and status still knows the URL.
  [ "$(leopard status --json | j "\$d[\"web\"][\"url\"]")" = http://127.0.0.1:8080 ]
  echo "settings persist: ok"
  # PHP on the command line keeps warnings out of stdout.
  [ "$(leopard php -r "trigger_error(\"w\", E_USER_WARNING); echo \"v\";" 2>/dev/null)" = v ]
  echo "cli stdout clean: ok"
  set +e; leopard site info nope --json >/tmp/e.json; code=$?; set -e
  [ $code = 3 ] && [ "$(j "\$d[\"error\"][\"code\"]" </tmp/e.json)" = not_found ]
  echo "not found: exit 3 with json error: ok"
  [ "$(j "\$d[\"api\"]" </tmp/e.json)" = 1 ] && [ "$(leopard status --json | j "\$d[\"api\"]")" = 1 ]
  echo "json carries api 1: ok"

  # Another command holding a lock makes this one fail fast with exit 6.
  for f in .lock .sites.lock; do
    sh -c "exec 9>/var/lib/leopard/$f; flock 9; sleep 4" & holder=$!
    sleep 0.5
    if [ $f = .lock ]; then cmd="leopard start --json"; else cmd="leopard site create held --json"; fi
    set +e; LEOPARD_LOCK_WAIT=1 timeout 20 $cmd >/tmp/e.json; code=$?; set -e
    wait $holder
    [ $code = 6 ] && [ "$(j "\$d[\"error\"][\"code\"]" </tmp/e.json)" = busy ]
  done
  [ ! -e /var/lib/leopard/sites/held ]
  echo "held lock: exit 6 busy, no hang: ok"

  mkdir -p /src/hello && printf "<?php\n/* Plugin Name: Hello Leopard */\n" >/src/hello/hello.php
  leopard site create gb --title "GB Test" --plugin /src/hello --activate hello --json >/tmp/gb.json
  url=$(j "\$d[\"site\"][\"url\"]" </tmp/gb.json)
  [ "$url" = http://127.0.0.1:8080/gb ]
  [ "$(leopard site wp gb option get home)" = "$url" ]
  leopard site wp gb plugin is-active hello
  leopard php -r "echo file_get_contents(\"$url/wp-login.php\");" | grep -q user_login
  leopard php -r "echo file_get_contents(\"$url/sample-page/\");" | grep -q "Sample Page"
  echo "site create, pretty permalinks, plugin link: ok"
  http_code() { leopard php -r "@file_get_contents(\"\$argv[1]\"); echo explode(\" \", \$http_response_header[0])[1];" "$1"; }
  [ "$(http_code "$url/.htaccess")" = 403 ] && [ "$(http_code "$url/wp-login.php")" = 200 ]
  echo ".htaccess denied: ok"

  set +e; leopard site create gb --json >/tmp/e.json; code=$?; set -e
  [ $code = 4 ] && [ -f /var/lib/leopard/sites/gb/wp-config.php ]
  echo "second create refused, site intact: ok"

  # A failed create removes what it made.
  set +e; leopard site create broken --activate no-such-plugin >/dev/null 2>&1; code=$?; set -e
  [ $code != 0 ] && [ ! -e /var/lib/leopard/sites/broken ] && ! leopard mysql -e "use broken" 2>/dev/null
  echo "failed create rolled back: ok"

  leopard site wp gb db export /tmp/gb.sql --quiet
  leopard site create copy --dir /srv/copy --import-sql /tmp/gb.sql --admin-password secret --json >/dev/null
  [ "$(leopard site wp copy option get home)" = http://127.0.0.1:8080/copy ]
  leopard site wp copy user check-password admin secret
  echo "import with url rewrite and --dir: ok"

  leopard site delete copy --json >/dev/null
  [ ! -e /srv/copy ] && [ -f /src/hello/hello.php ] && ! leopard mysql -e "use copy" 2>/dev/null
  echo "delete removes folder and db, keeps plugin source: ok"
  [ "$(leopard site list --json | j "count(\$d[\"sites\"])")" = 1 ]

  leopard xdebug coverage >/dev/null
  printf "<?php echo ini_get(\"xdebug.mode\");" >/www/x.php
  [ "$(leopard php -r "echo file_get_contents(\"http://127.0.0.1:8080/x.php\");")" = coverage ]
  leopard xdebug off >/dev/null
  echo "xdebug switch reaches apache: ok"

  timeout 10 leopard logs -n 5 >/dev/null
  echo "logs returns: ok"

  # A child that writes a lot to stderr must not hang site create.
  mkdir -p /src/noisy && printf "<?php\n/* Plugin Name: Noisy */\nfwrite(STDERR, str_repeat(\"x\", 200000));\n" >/src/noisy/noisy.php
  timeout 120 leopard site create noisy --plugin /src/noisy --activate noisy >/dev/null 2>&1
  leopard site delete noisy >/dev/null
  echo "noisy child does not hang create: ok"

  # A create killed outright leaves a record that site delete can clean up.
  timeout -s KILL 2 leopard site create killed >/dev/null 2>&1 || true
  leopard site delete killed >/dev/null
  [ ! -e /var/lib/leopard/sites/killed ] && [ ! -e /var/lib/leopard/conf/sites/killed.conf ] && ! leopard mysql -e "use killed" 2>/dev/null
  echo "killed create cleaned by delete: ok"

  # Only a successful start saves environment overrides.
  LEOPARD_PORT=9999 leopard xdebug off --json | j "\$d[\"xdebug_mode\"]" | grep -qx off
  [ "$(leopard config --json | j "\$d[\"port\"]")" = 8080 ]
  echo "env override not saved by other commands: ok"

  # A busy new port fails fast and leaves the running Apache alone.
  leopard php -r "\$s = stream_socket_server(\"tcp://0.0.0.0:8085\"); sleep(30);" & busy=$!
  sleep 1
  set +e; LEOPARD_PORT=8085 leopard start --json >/tmp/e.json; code=$?; set -e
  kill $busy
  [ $code = 1 ] && [ "$(j "\$d[\"error\"][\"code\"]" </tmp/e.json)" = web_start_failed ]
  leopard php -r "echo file_get_contents(\"$url/wp-login.php\");" | grep -q user_login
  [ "$(leopard config --json | j "\$d[\"port\"]")" = 8080 ]
  echo "busy port refused, site still served: ok"

  # Sites keep working after the database port changes.
  leopard config set db_port 3399 >/dev/null 2>&1
  leopard restart >/dev/null
  leopard php -r "echo file_get_contents(\"$url/wp-login.php\");" | grep -q user_login
  [ "$(leopard site wp gb option get home)" = "$url" ]
  echo "db port change keeps sites working: ok"
  # A good start deletes runtimes left by older Leopard files, and nothing else.
  mkdir /var/lib/leopard/runtime/0123456789ab /var/lib/leopard/runtime/keep-me
  leopard restart >/dev/null
  [ ! -e /var/lib/leopard/runtime/0123456789ab ] && [ -d /var/lib/leopard/runtime/keep-me ]
  echo "old runtimes removed: ok"
  leopard doctor --json | j "\$d[\"ok\"] ? \"doctor: ok\" : \"doctor failed\"" | grep -x "doctor: ok"
  leopard stop --json | j "\$d[\"db\"][\"running\"] ? \"bad\" : \"stop --json: ok\""
'
docker rm -f "$c" >/dev/null
