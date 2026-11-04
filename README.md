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
change `php8.3` in the URL. Each release also lists their SHA-256 sums in
`SHA256SUMS`.

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

## What you get

| Part | Details |
| --- | --- |
| Web | Apache 2.4 with mod_php, mod_rewrite, and `.htaccess` (AllowOverride All) on port 8080 |
| PHP | Upstream PHP with mysqli, pdo_mysql, gd, imagick, intl, mbstring, zip, curl, xml, xsl, exif, bcmath, sqlite, opcache, xdebug, and more |
| Database | MariaDB on 127.0.0.1:3306. User `dev`, password `dev`, full rights. A `wordpress` database already exists. `root` has no password. |
| Tools | `php`, `wp` (WP-CLI), `composer`, `mysql`, `mysqladmin`, `mysqldump`, after `leopard link` or in `leopard shell` |

Run `leopard version` for exact versions.

## Commands

Run `leopard help` for the full list. The main ones:

- `start [docroot]`, `stop`, `restart`, `status`, `doctor`
- `site create|list|info|path|delete|wp`
- `logs [apache|access|php|mariadb] [-n N] [-f]`. Prints and exits unless `-f`.
- `xdebug [off|debug|develop|coverage|profile|trace]`. Off by default. For one
  command, set `XDEBUG_MODE` instead.
- `config [set port|db_port|url VALUE]`
- `service install|uninstall|status`. Starts Leopard at boot with systemd. This
  is the only command that writes outside `$LEOPARD_HOME`.
- `php`, `wp`, `composer`, `mysql`, `shell`, `link`, `version`, `skill`

Settings come from environment variables. `start` saves them, so later commands
use the same values without the variables:

- `LEOPARD_HOME` sets where data, config, sites, and logs live. The default is `~/.leopard`.
- `LEOPARD_PORT` sets the web port. The default is 8080.
- `LEOPARD_DB_PORT` sets the database port. The default is 3306.
- `LEOPARD_URL` sets the base URL that browsers use. The default is `http://localhost:<port>`.

To run two stacks side by side, give each its own `LEOPARD_HOME` and ports.

## For AI agents

Leopard is built to be driven by agents:

- Every command that prints data takes `--json`. Errors then print
  `{"ok": false, "error": {"code", "message", "hint"}}`.
- Exit codes are stable: 0 ok, 1 error, 2 bad usage, 3 not found, 4 already
  exists, 5 not running.
- `start` and `site create` are safe to retry. `site create` never adopts an
  existing folder or database, and a failed create removes what it made.
- No command waits for input or runs forever, except `logs -f` and `shell`.
- PHP on the command line writes errors to stderr, so captured output stays clean.

`leopard skill` prints [the agent guide](skills/using-leopard/SKILL.md). Install it
where your agent reads skills, for example
`mkdir -p ~/.agents/skills/using-leopard && leopard skill > ~/.agents/skills/using-leopard/SKILL.md`.

## Running as root

Apache refuses to serve pages as root. When you start Leopard as root, Apache's
workers run as the owner of the docroot. If root owns the docroot, they run as
`nobody` (uid 65534). WordPress then cannot write uploads or install plugins
until you run `chown -R 65534:65534 <docroot>`. Starting as a normal user avoids this.

## Build

```sh
./build.sh                    # PHP 8.3 by default
PHP_VERSION=8.4 ./build.sh    # any version that has a php:<version>-apache image
./test.sh dist/leopard-php8.3-linux-aarch64
```

The build needs Docker on the build machine only. It takes upstream PHP from the
official `php:<version>-apache` image, plus Apache and MariaDB from Debian. It then
copies each binary, every shared library the binaries load, and the glibc loader
into the bundle. Each binary runs through that bundled loader, so the bundle runs
on any Linux distro (Alpine with musl included) with the same CPU arch. The output
matches the build machine's arch. Build on x86_64 to get an x86_64 file.

`test.sh` starts bare Alpine, Debian, and Ubuntu containers. In each one it checks
cold and warm start, PHP to MariaDB through Apache, the PHP extensions, and
restarts. It then installs WordPress by hand, and checks the agent contract:
JSON output, exit codes, and the full site lifecycle.
