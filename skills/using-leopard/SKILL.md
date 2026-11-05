---
name: using-leopard
description: "Runs PHP sites (WordPress first) on Leopard, a single-file Apache, PHP, and MariaDB or MySQL stack for Linux, with a PHP version and Xdebug mode per site. Use when a task needs a local web server, a MySQL-compatible database, PHP, WP-CLI, or Composer without Docker, apt, or root, or when a machine already has a `leopard` command or a ~/.leopard folder."
---

# Leopard

Leopard is one file that holds Apache, PHP (in PHP-FPM, one pool per site),
MariaDB, WP-CLI, and Composer. Other PHP versions and MySQL come as packs from
the same release. It changes nothing outside `$LEOPARD_HOME` (default `~/.leopard`;
`/var/lib/leopard` as root), except `leopard service install` and
`leopard skill setup`.

## Find or get Leopard

```sh
command -v leopard || ls ~/.leopard/bin/leopard
# Not there: install it. It needs no root and changes nothing outside
# ~/.leopard and ~/.local/bin (/var/lib/leopard and /usr/local/bin as root).
curl -fsSL https://raw.githubusercontent.com/dinhtungdu/leopard/main/install.sh | sh
```

`LEOPARD_PHP=8.5` before `sh` picks the default PHP (8.3, 8.4, or 8.5). Running
the line again upgrades Leopard and restarts it if it runs. If `leopard` is not on
PATH afterwards, the installer prints its full path; use that.

## Read the state first

```sh
leopard status --json
```

Read ports, URLs, the database login, the default PHP and Xdebug mode, the
running PHP-FPM masters, and each site's PHP and Xdebug from this output.
Do not guess them, and do not read Leopard's config files. Settings persist:
after the first `start`, every command uses the same ports and URL.

## Start it

```sh
LEOPARD_PORT=8082 LEOPARD_DB_PORT=3307 LEOPARD_URL=http://<host-ip>:8082 leopard start --json
```

- `start` is safe to rerun. It starts what is stopped and reloads what runs.
- Set the ports when 8080 or 3306 may be taken; `start` fails fast with
  `db_start_failed` or `web_start_failed` if a port is busy.
- Set `LEOPARD_URL` to the address the browser uses. Site URLs are built from it.
- `start` without a folder serves `$LEOPARD_HOME/www` at `/`, a page that links
  every site. `start DIR` serves DIR there instead, and later starts keep it.

## Sites

```sh
cd /path/to/plugin-or-theme && leopard site create --json   # named after the folder
leopard site create NAME --plugin /path/to/plugin-checkout --activate plugin-slug --json
leopard site create NAME --theme /path/to/theme-checkout --json
leopard site create NAME --port 8090 --json            # at http://<host>:8090/, not <url>/NAME/
leopard site create NAME --import-sql db.sql --content wp-content.zip --json
leopard site list --json
leopard site info NAME --json
leopard site wp NAME plugin list       # WP-CLI with --path set for you
leopard site delete NAME --json
```

- A site lives at `<url>/NAME/`. Its folder is `$LEOPARD_HOME/sites/NAME`, or
  `--dir PATH`. Its database is named `NAME`. The JSON output has `url`,
  `admin_url`, `dir`, `db`, and the admin login (default `admin` / `password`).
- Without NAME, run inside a plugin folder (a top-level PHP file with a
  `Plugin Name:` header) or a theme folder (`style.css` with `Theme Name:`).
  The site takes the folder's name, and the folder is linked and activated.
  Elsewhere, no NAME is exit 2. The folder name must be a valid site name.
- `create` starts the stack if it is not running.
- `--port PORT` serves the site at the root of its own port, on the same host
  as the base URL. Use it for code that breaks under a subfolder. The port must
  be free and not used by Leopard or another site, or `create` exits 4 with
  `port_in_use`. Read the address from `url`; `port` is null for a path site.
- `--plugin [SLUG=]PATH` symlinks a checkout into `wp-content/plugins`. It never
  copies it. Repeat it for several plugins. `--activate SLUG` activates one.
  `--theme [SLUG=]PATH` symlinks a theme into `wp-content/themes` and activates it.
- `--import-sql` imports a dump instead of a fresh install. `--content` unpacks
  a zip into the site first. URLs are rewritten from the dump's `home`, or from
  `--from-url`. The admin user gets the given password.
- `create` refuses an existing site, folder, or database (exit 4). It never
  adopts them. If a step fails or the run is interrupted, it removes what that
  run made. If it was killed outright, `site info` shows `"state": "creating"`;
  run `site delete NAME` to clean up, then create again.
- `delete` removes only a site that `create` made: its database and folder.
  Symlinked plugin and theme checkouts are left untouched.
- Without network access to wordpress.org, `create` uses the newest WordPress
  in the WP-CLI cache. Pass `--wp-version` to pick one.

## PHP version and Xdebug per site

```sh
leopard site create NAME --php 8.3 --xdebug debug --json   # fetches PHP 8.3 if needed
leopard site set NAME --php 8.5 --json                     # "changed": false if already so
leopard site set NAME --xdebug default                     # follow `leopard xdebug`
leopard site wp NAME ...     # WP-CLI on the site's PHP
leopard site php NAME ...    # the site's PHP
leopard php list --available --json
```

- A site without `--php` follows the default PHP (`leopard php default V`). A
  site with `--php` is pinned to that exact build.
- Switching drops no requests. Do it while tests run if you need to.
- `--xdebug debug` starts a session only on requests with `XDEBUG_TRIGGER=1`.
  Sites without Xdebug never load it. `leopard xdebug MODE` sets the default
  for sites without their own mode, and for `leopard php` and `wp`.
- Fetching needs the network. Offline, `pack_missing` or `download_failed` says
  so; install a pack file with `leopard packs add FILE` instead.
- `.htaccess` `php_value` lines do not work under PHP-FPM. Use `.user.ini`.

## MySQL instead of MariaDB

```sh
leopard db use mysql-8.4 --copy-data --json   # copies every database
leopard db use mariadb --json                 # back; MariaDB data is untouched
leopard db list --available --json
```

One database server runs at a time, on the same port and socket, so sites keep
working. Without `--copy-data`, the new engine starts empty, except the
`wordpress` database and the `dev` user. `db prune ENGINE` deletes an inactive
engine's data, so run it only when the user asks.

## Tools

Use the bundled tools, not the system ones, so PHP and the database match:

```sh
leopard shell -c 'composer install && vendor/bin/phpunit'
leopard wp ... | leopard php ... | leopard composer ... | leopard mysql ...
```

`leopard shell` puts `php`, `wp`, `composer`, `mysql`, `mysqladmin`,
`mysqldump`, and `leopard` first on PATH. PHP on the command line writes errors
to stderr, so `$(wp option get home)` captures only the value.

Database login for apps and tests: host `127.0.0.1`, port from `status`, user
`dev`, password `dev`. `leopard mysql` logs in as root. Sites made by
`site create` connect through the socket, so changing `db_port` never breaks them.

Only a successful `start` saves `LEOPARD_*` overrides. To change one setting
for good, use `leopard config set port|db_port|url VALUE` and `leopard restart`.
After replacing the Leopard file with a newer one, run `leopard restart`: it
unpacks the new version, runs the new servers, and refreshes the links in
`$LEOPARD_HOME/bin`.

## Debugging

```sh
leopard doctor --json          # every check; exit 1 if one fails
cur=$(leopard logs mark)       # before the step you want to check
# ... run the request or test ...
leopard logs --site NAME --since "$cur" --wait 5 --json
leopard logs --kind php,fpm,web,db,access --since 10m --level error --json
XDEBUG_MODE=coverage leopard shell -c 'vendor/bin/phpunit --coverage-text'
```

- `logs --json` returns `entries` (`time`, `site`, `kind`, `level`, `message`,
  `file`) and a new `cursor`. Pass the cursor to the next call to get only
  newer entries. `--wait N` waits up to N seconds for the first new entry.
- With `--site`, you get that site's PHP errors and requests only.
- `logs` prints and exits unless you pass `-f`. Xdebug is off by default.
- When a server fails to start, the error has `log` and `log_tail`. Read them
  before you retry.
- If a server died, `leopard start` repairs it. `doctor` shows which one.

## Exit codes and errors

0 ok, 1 error, 2 bad usage, 3 not found, 4 already exists, 5 not running,
6 busy. With `--json`, every reply has `"api": 1`, and errors print
`{"ok": false, "api": 1, "error": {"code", "message", "hint", "retryable"}}`.
Follow the `hint`. Retry when `retryable` is true.

Exit 6 (`busy`) means another Leopard command held the lock for longer than
`LEOPARD_LOCK_WAIT` seconds (default 120). It is safe to retry. A long
`site create` holds the site lock, so raise `LEOPARD_LOCK_WAIT` when you
create several sites in parallel.

## Boot

In a container with no init, run `leopard run` as the main process: it starts
everything, restarts a server that dies, and stops all on SIGTERM.

`leopard service install` adds a systemd unit that runs `leopard start` at
boot (`--run` uses `leopard run` and restarts it on failure). It writes outside `$LEOPARD_HOME`, so run it only
when the user asks for Leopard to survive reboots.

`leopard skill setup` installs a `using-leopard` skill into
`~/.agents/skills` and `~/.claude/skills` that reads this guide from the
installed Leopard. It also writes outside `$LEOPARD_HOME`, so run it only when
the user asks. `skill status --json` lists each path with `state`: `missing`,
`installed`, `outdated`, or `conflict` (a skill Leopard did not write; setup
exits 4 unless `--force`).
