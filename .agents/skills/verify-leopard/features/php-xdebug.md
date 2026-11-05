# PHP and Xdebug per site

A user pins one site to another PHP version or turns on Xdebug for one site. Other sites never load Xdebug, and no request fails while a site switches.

## Sub-features

- `php-pin` uses `site create --php V` or `site set NAME --php V` to fetch the pack, verify it, and pin the site.
- `php-default` uses `site set NAME --php default` to follow `leopard php default`.
- `xdebug-site` uses `site set NAME --xdebug debug` so that only that site connects to a debugger on `XDEBUG_TRIGGER=1`.
- `xdebug-noop` makes a repeat `set` report `"changed": false`.
- `switch-under-load` keeps failed requests at zero during switches.

## How to get to it (user POV)

- `leopard site set NAME --php 8.4 | --xdebug debug|off|develop|default`
- `leopard site php NAME ...`, `leopard php list [--available]`, `leopard xdebug MODE`

## Driving it with lv

Preconditions:

- Baseline, with a second PHP pack in `dist/packs`. Build it with `PHP_VERSIONS="8.3 8.4" ./build.sh`, then `$L up` again. Without one, report `php-pin` as not reachable.
- A site `gb` exists: `$L x 'leopard site create gb --json >/dev/null'`.
- A probe in two docroots: `$L x 'printf "<?php echo PHP_VERSION, \" \", function_exists(\"xdebug_info\") ? implode(\",\", xdebug_info(\"mode\")) : \"none\";" | tee /var/lib/leopard/sites/gb/v.php /var/lib/leopard/www/v.php >/dev/null'`.

- **Pin PHP.** `$L proof php-pin 'leopard site create v2 --php 8.4 --json'`, then `$L x 'cp /var/lib/leopard/sites/gb/v.php /var/lib/leopard/sites/v2/'`. `curl $LV_URL/v2/v.php` starts with `8.4.`, and `curl $LV_URL/gb/v.php` starts with `8.3.`.
- **Xdebug one site.** `$L proof xdebug-site 'leopard site set gb --xdebug debug --json'`. `curl $LV_URL/gb/v.php` ends in `debug`, and `curl $LV_URL/v.php` ends in `none`.
- **No-op.** Repeat the same command. `.changed` is `false`.
- **Under load.** Loop `curl -s -o /dev/null -w '%{http_code}\n' $LV_URL/gb/v.php` for 6 s on the host while `$L x` runs `leopard site set gb --xdebug MODE` for `off`, `develop`, and `debug`. Every code is `200`. Save the counts with `proof`.
- **Back.** `leopard site set gb --xdebug default`.

## Gotchas

- `leopard php -r` runs PHP, while `leopard php list|install|default` manages versions.
- A pack fetch uses `LEOPARD_PACKS_URL=file:///packs`, set by `lv up`. A pack missing from `dist/packs` is `pack_missing`, not a bug.
- The debugger check needs a listener on 127.0.0.1:9003 inside the container. See `dbgp()` in `test.sh` for the pattern.
