---
name: using-leopard
description: "Runs PHP sites (WordPress first) on Leopard, a single-file Apache, PHP, and MariaDB stack for Linux. Use when a task needs a local web server, a MySQL-compatible database, PHP, WP-CLI, or Composer without Docker, apt, or root, or when a machine already has a `leopard` command or a ~/.leopard folder."
---

# Leopard

Leopard is one file that holds Apache with mod_php, PHP, MariaDB, WP-CLI, and
Composer. It changes nothing outside `$LEOPARD_HOME` (default `~/.leopard`;
`/var/lib/leopard` as root), except `leopard service install`.

## Find or get Leopard

```sh
command -v leopard || ls ~/.leopard/bin/leopard
# Not there: download the file for this machine (x86_64 or aarch64).
curl -fLo leopard https://github.com/dinhtungdu/leopard/releases/latest/download/leopard-php8.3-linux-$(uname -m)
chmod +x leopard
```

Builds exist for PHP 8.3, 8.4, and 8.5: change `php8.3` in the file name.

## Read the state first

```sh
leopard status --json
```

Read ports, URLs, the database login, Xdebug mode, and sites from this output.
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

## Sites

```sh
leopard site create NAME --plugin /path/to/plugin-checkout --activate plugin-slug --json
leopard site create NAME --import-sql db.sql --content wp-content.zip --json
leopard site list --json
leopard site info NAME --json
leopard site wp NAME plugin list       # WP-CLI with --path set for you
leopard site delete NAME --json
```

- A site lives at `<url>/NAME/`. Its folder is `$LEOPARD_HOME/sites/NAME`, or
  `--dir PATH`. Its database is named `NAME`. The JSON output has `url`,
  `admin_url`, `dir`, `db`, and the admin login (default `admin` / `password`).
- `--plugin [SLUG=]PATH` symlinks a checkout into `wp-content/plugins`. It never
  copies it. Repeat it for several plugins. `--activate SLUG` activates one.
- `--import-sql` imports a dump instead of a fresh install. `--content` unpacks
  a zip into the site first. URLs are rewritten from the dump's `home`, or from
  `--from-url`. The admin user gets the given password.
- `create` refuses an existing site, folder, or database (exit 4). It never
  adopts them. If a step fails or the run is interrupted, it removes what that
  run made. If it was killed outright, `site info` shows `"state": "creating"`;
  run `site delete NAME` to clean up, then create again.
- `delete` removes only a site that `create` made: its database and folder.
  Symlinked plugin checkouts are left untouched.
- Without network access to wordpress.org, `create` uses the newest WordPress
  in the WP-CLI cache. Pass `--wp-version` to pick one.

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
leopard logs php -n 100        # also apache, access, mariadb; -f follows
leopard xdebug debug           # or coverage, profile, develop, off
XDEBUG_MODE=coverage leopard shell -c 'vendor/bin/phpunit --coverage-text'
```

`logs` prints and exits unless you pass `-f`. Xdebug is off by default.

## Exit codes and errors

0 ok, 1 error, 2 bad usage, 3 not found, 4 already exists, 5 not running. With
`--json`, errors print `{"ok": false, "error": {"code", "message", "hint"}}`.
Follow the `hint`.

## Boot

`leopard service install` adds a systemd unit that runs `leopard start` at
boot. It is the only command that writes outside `$LEOPARD_HOME`, so run it only
when the user asks for Leopard to survive reboots.
