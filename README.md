# Leopard

Apache, PHP, and MariaDB in one file. Copy it to a Linux machine or container,
run it, and you have a PHP server with a database. It installs nothing on the
machine. There is no apt, no Docker, and no setup script.

```sh
curl -fLo leopard https://github.com/dinhtungdu/leopard/releases/latest/download/leopard-php8.3-linux-$(uname -m)
chmod +x leopard
./leopard start /path/to/docroot   # defaults to the current directory
```

Files exist for x86_64 and aarch64 (ARM64) Linux. Each release also lists their
SHA-256 sums in `SHA256SUMS`.

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

## WordPress

```sh
mkdir site && cd site
leopard start
wp core download
wp config create --dbname=wordpress --dbuser=dev --dbpass=dev --dbhost=127.0.0.1
wp core install --url=http://localhost:8080 --title=Dev --admin_user=admin --admin_password=admin --admin_email=dev@example.com --skip-email
```

For Laravel, serve the `public` folder: `leopard start public`.

## What you get

| Part | Details |
| --- | --- |
| Web | Apache 2.4 with mod_php, mod_rewrite, and `.htaccess` (AllowOverride All) on port 8080 |
| PHP | Upstream PHP with mysqli, pdo_mysql, gd, intl, mbstring, zip, curl, xml, exif, bcmath, sqlite, opcache |
| Database | MariaDB on 127.0.0.1:3306. User `dev`, password `dev`, full rights. A `wordpress` database already exists. `root` has no password. |
| Tools | `php`, `wp` (WP-CLI), `composer`, `mysql`, `mysqladmin`, `mysqldump`, after `leopard link` or in `leopard shell` |

Run `leopard version` for exact versions.

## Commands

`start [docroot]`, `stop`, `restart`, `status`, `logs`, `version`, `php`, `mysql`, `wp`, `composer`, `shell`, `link`.

Settings are environment variables:

- `LEOPARD_HOME` sets where data, config, and logs live. The default is `~/.leopard`.
- `LEOPARD_PORT` sets the web port. The default is 8080.
- `LEOPARD_DB_PORT` sets the database port. The default is 3306.

To run two stacks side by side, give each its own `LEOPARD_HOME` and ports.

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
cold and warm start, PHP to MariaDB through Apache, and restarts. It ends with a
full WordPress install.
