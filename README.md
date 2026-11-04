# Leopard

Apache, PHP, and MariaDB in one file. Copy it to a Linux machine or container,
run it, and you have a PHP server with a database. It installs nothing on the
machine. There is no apt, no Docker, and no setup script.

```sh
curl -fLo leopard https://github.com/dinhtungdu/leopard/releases/latest/download/leopard-php8.3-linux-$(uname -m)
chmod +x leopard
./leopard start /path/to/docroot   # defaults to the current directory
```

Files exist for PHP 8.3, 8.4, and 8.5 on x86_64 and aarch64 (ARM64) Linux:
change `php8.3` in the URL. The PHP in the file is the default. Any file can
add the other PHP versions and MySQL later (see below). Each release also lists
the SHA-256 sums of its files and packs in `SHA256SUMS`.

The first start unpacks the file into `~/.leopard` and takes about 2.5 seconds.
Later starts take under a second.

## Use php, wp, composer, and mysql as plain commands

Leopard changes nothing on the system by default. The quickest way:

```sh
./leopard-php8.3-linux-x86_64 shell
php -v; wp --info; composer install; mysql
exit                                       # back to your normal shell
```

`leopard shell` opens your usual shell (`$SHELL`) with `php`, `wp`, `composer`,
`mysql`, `mysqladmin`, `mysqldump`, and `leopard` first on PATH. Only that shell sees them. It also sets
`LEOPARD_SHELL=1`, which a prompt can show. `leopard shell -c 'wp plugin list'`
runs one command the same way.

To use the commands from your own shell instead:

```sh
./leopard-php8.3-linux-x86_64 link
export PATH="$HOME/.leopard/bin:$PATH"     # as root: /var/lib/leopard/bin
php -v; wp --info; composer install; mysql
```

`link` puts `leopard`, `php`, `wp`, `composer`, `mysql`, `mysqladmin`, and
`mysqldump` in `~/.leopard/bin`
(`/var/lib/leopard/bin` as root). That folder belongs to Leopard, and the `export`
affects only the current shell. If you want the commands everywhere, name a folder
yourself, for example `leopard link /usr/local/bin`. `link` never replaces a
tool that the system installed.

Without linking, `leopard php`, `leopard wp`, `leopard composer`, and
`leopard mysql` work the same way.

## WordPress sites

```sh
leopard start
leopard site create shop --plugin ~/code/my-plugin --activate my-plugin
# url: http://localhost:8080/shop/  admin: admin / password
leopard site wp shop plugin list
leopard site delete shop
```

Each site gets its own folder (`~/.leopard/sites/<name>`, or `--dir`), its own
database, and the URL `<url>/<name>/` with pretty permalinks. `--plugin`
symlinks a checkout, so edits show up right away. To start from a snapshot,
use `--import-sql dump.sql [--content wp-content.zip]`; Leopard rewrites the
URLs and sets the admin password. `site delete` removes only sites that
`site create` made, and never touches the linked checkouts.

If the browser reaches the machine at another address, save it once:
`LEOPARD_URL=http://100.1.2.3:8080 leopard start`.

For Laravel, serve the `public` folder: `leopard start public`.

## PHP versions, Xdebug, and MySQL per site

Each site can run its own PHP version and its own Xdebug mode, side by side:

```sh
leopard site create old --php 8.3          # downloads PHP 8.3 the first time
leopard site set old --php 8.5 --xdebug debug
leopard site set old --xdebug default      # back to the default (off)
leopard site wp old eval 'echo PHP_VERSION;'   # WP-CLI runs on the site's PHP too
leopard php list --available | php install 8.4 | php default 8.4
```

Switching takes a moment and drops no requests. A site with Xdebug in debug
mode starts a session on a request with `XDEBUG_TRIGGER=1` (or the browser
helper's cookie). Sites without Xdebug never load it, so they stay fast.

The database is MariaDB by default. To use MySQL instead:

```sh
leopard db use mysql-8.4 --copy-data      # copies every database over
leopard db use mariadb                    # back; the MariaDB data is untouched
```

Each engine keeps its own data folder. Sites keep working, because they
connect through a socket that stays the same.

Extra versions come as packs from the same GitHub release as the file, and
Leopard checks each one against the SHA-256 that the file lists. Offline, use
`leopard packs add FILE` with a pack downloaded elsewhere. `leopard packs gc`
deletes packs that nothing uses.

## What you get

| Part | Details |
| --- | --- |
| Web | Apache 2.4 (event MPM) with mod_rewrite and `.htaccess` (AllowOverride All) on port 8080. PHP runs in PHP-FPM, one pool per site. |
| PHP | Upstream PHP with mysqli, pdo_mysql, gd, imagick, intl, mbstring, zip, curl, xml, xsl, exif, bcmath, sqlite, opcache, xdebug, and more |
| Database | MariaDB (or MySQL 8.4) on 127.0.0.1:3306. User `dev`, password `dev`, full rights. A `wordpress` database already exists. `root` has no password. |
| Tools | `php`, `wp` (WP-CLI), `composer`, `mysql`, `mysqladmin`, `mysqldump`, after `leopard link` or in `leopard shell` |

Run `leopard version` for exact versions.

PHP runs in PHP-FPM, not inside Apache, so `php_value` and `php_flag` lines in
`.htaccess` do not work. Put those settings in a `.user.ini` file in the site
folder. `leopard doctor` warns about sites that have them.

## Commands

Run `leopard help` for the full list. The main ones:

- `start [docroot]`, `stop`, `restart`, `status`, `doctor`
- `run`: start, then stay in the foreground and restart any server that dies.
  For containers (`CMD ["leopard", "run"]`) and systemd.
- `site create|list|info|path|set|delete|wp|php`
- `logs [--site NAME] [--kind php,access,fpm,web,db] [--since 10m|ISO|CURSOR] [--wait N] [-n N] [-f]`,
  and `logs mark`. Prints and exits unless `-f`.
- `xdebug [off|debug|develop|coverage|profile|trace]`: the default for sites
  without their own mode. Off by default. For one command, set `XDEBUG_MODE`.
- `php list|install|default`, `db list|use|prune`, `packs list|add|gc`
- `config [set port|db_port|url VALUE]`
- `service install [--run]|uninstall|status`. Starts Leopard at boot with
  systemd. With `--run`, systemd runs `leopard run` and restarts it if it fails.
  This is the only command that writes outside `$LEOPARD_HOME`.
- `php`, `wp`, `composer`, `mysql`, `shell`, `link`, `version`, `skill`

Settings come from environment variables. `start` saves them, so later commands
use the same values without the variables:

- `LEOPARD_HOME` sets where data, config, sites, and logs live. The default is `~/.leopard`.
- `LEOPARD_PORT` sets the web port. The default is 8080.
- `LEOPARD_DB_PORT` sets the database port. The default is 3306.
- `LEOPARD_URL` sets the base URL that browsers use. The default is `http://localhost:<port>`.
- `LEOPARD_LOCK_WAIT` sets how many seconds a command waits for another one before it exits 6. The default is 120. It is not saved.

To run two stacks side by side, give each its own `LEOPARD_HOME` and ports.

## For AI agents

Leopard is built to be driven by agents:

- Every command that prints data takes `--json`. Every JSON reply has
  `"api": 1`. Within api 1, fields are only added, never renamed or removed.
  Errors print `{"ok": false, "api": 1, "error": {"code", "message", "hint", "retryable"}}`.
  When a server fails to start, the error also has `log` and `log_tail`.
- Exit codes are stable: 0 ok, 1 error, 2 bad usage, 3 not found, 4 already
  exists, 5 not running, 6 busy.
- Exit 6 means another command held Leopard's lock for longer than
  `LEOPARD_LOCK_WAIT` seconds (default 120). Retry it. No command waits forever for a lock.
- `start` and `site create` are safe to retry. `site create` never adopts an
  existing folder or database, and a failed create removes what it made.
  Commands that change settings return `"changed": true|false`.
- `start` repairs anything that died, even after `kill -9`.
- `logs mark` returns a cursor. Run a test, then `logs --site NAME --since CURSOR --json`
  returns exactly what that site logged since, as entries with `time`, `kind`,
  `level`, and `message`. `--wait N` waits up to N seconds for the first one.
- No command waits for input or runs forever, except `logs -f`, `shell`, and `run`.
- PHP on the command line writes errors to stderr, so captured output stays clean.

`leopard skill` prints [the agent guide](skills/using-leopard/SKILL.md). Install it
where your agent reads skills, for example
`mkdir -p ~/.agents/skills/using-leopard && leopard skill > ~/.agents/skills/using-leopard/SKILL.md`.

## Running as root

Apache and PHP-FPM refuse to serve pages as root. When you start Leopard as
root, each site's PHP runs as the owner of its folder. If root owns the folder,
it runs as `nobody` (uid 65534). WordPress then cannot write uploads or install plugins
until you run `chown -R 65534:65534 <docroot>`. Starting as a normal user avoids this.

## Build

```sh
./build.sh                               # PHP 8.3 by default
PHP_VERSIONS="8.3 8.5" ./build.sh        # one file per version
./build.sh pack mysql 8.4                # a MySQL pack
./test.sh dist/leopard-php8.3-linux-aarch64
```

The build needs Docker on the build machine only. It makes packs: PHP from the
official `php:<version>-fpm` image, MySQL from `mysql:<version>`, and a core with
Apache and MariaDB from Debian. Each pack holds its binaries, every shared
library they load, and the glibc loader. A file is the core plus one PHP pack,
and it lists every pack built with it and its SHA-256. Each binary runs through that bundled loader, so the bundle runs
on any Linux distro (Alpine with musl included) with the same CPU arch. The output
matches the build machine's arch. Build on x86_64 to get an x86_64 file.

`test.sh` starts bare Alpine, Debian, and Ubuntu containers. In each one it checks
cold and warm start, PHP to MariaDB through Apache, the PHP extensions, and
restarts. It then installs WordPress by hand, and checks the agent contract:
JSON output, exit codes, the full site lifecycle, per-site PHP and Xdebug,
switching under load, log cursors, MySQL, and repair after `kill -9`.
