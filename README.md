# Leopard

**A WordPress dev stack in one file.** Apache, PHP, MariaDB, WP-CLI, and
Composer, ready in seconds on any Linux machine. No Docker, no apt, no root.

```sh
curl -fsSL https://raw.githubusercontent.com/dinhtungdu/leopard/main/install.sh | sh
```

```sh
leopard start
leopard site create mysite
```

Open http://localhost:8080/mysite/ and log in as `admin` / `password`.
http://localhost:8080/ lists every site.

## Why Leopard

- **Fast.** Install is one download. The first start takes about 2.5 seconds, later
  starts under one.
- **Self-contained.** Everything lives in `~/.leopard`, plus the `leopard` command.
  The system PHP, Apache, and MySQL stay untouched. Runs on any distro, Alpine included.
- **Built for agents.** Every command speaks JSON, exits with a stable code, and
  never waits for input. One command teaches your coding agent to use it.

## Develop a plugin or theme

Run `site create` inside your plugin or theme folder. Leopard names the site after
the folder, links your code into it, activates it, and starts the stack if needed.
Edits show up on the next page load.

```sh
cd ~/code/my-plugin
leopard site create                      # http://localhost:8080/my-plugin/
leopard site wp my-plugin plugin list    # WP-CLI, already pointed at the site
leopard logs --site my-plugin            # this site's errors and requests
```

From anywhere else, name the site and the code:
`leopard site create dev --plugin ~/code/my-plugin --activate my-plugin --theme ~/code/my-theme`.

Need a site at the root of its own address instead of under `/mysite/`?
`leopard site create mysite --port 8090` serves it at http://localhost:8090/.

Start from a copy of a real site instead of a fresh install:

```sh
leopard site create shop --import-sql shop.sql --content wp-content.zip
```

Leopard rewrites the URLs in the dump and sets the admin password to `password`.

## Use it with AI agents

```sh
leopard skill setup
```

This gives Claude Code, Codex, Amp, OpenCode, and Pi a `using-leopard` skill. The
skill reads its guide from your installed Leopard, so it stays current when you
upgrade. Agents then get:

| | |
| --- | --- |
| JSON everywhere | `--json` on every command. Each reply has `"api": 1`; fields are only ever added. |
| Clear errors | `{"ok": false, "error": {"code", "message", "hint", "retryable"}}`, plus the server log tail when a server fails to start. |
| Stable exit codes | 0 ok, 1 error, 2 bad usage, 3 not found, 4 already exists, 5 not running, 6 busy. |
| Safe retries | `start` and `site create` can run again. A failed `site create` removes what it made. |
| Precise logs | `cur=$(leopard logs mark)`, run a test, then `leopard logs --site dev --since "$cur" --json`. |
| No hangs | Nothing waits for input. A busy lock exits 6 after `LEOPARD_LOCK_WAIT` seconds (default 120). |

## Everyday commands

| Command | What it does |
| --- | --- |
| `leopard start` / `stop` / `restart` | Run the stack. `start` also repairs anything that died. |
| `leopard status` | Ports, URLs, database login, and each site's PHP and Xdebug. |
| `leopard site create [NAME]` | A WordPress site at `<url>/NAME/`, with its own folder and database. No NAME inside a plugin or theme folder uses that folder. |
| `leopard site list` / `info` / `delete` | Manage sites. `delete` never touches linked code. |
| `leopard site set NAME --php 8.4` | Switch one site's PHP. No request fails during the switch. |
| `leopard site set NAME --xdebug debug` | Xdebug for one site. Other sites never load it. |
| `leopard site wp NAME ...` | WP-CLI for that site, on its PHP. |
| `leopard logs` | PHP, web, and database logs. `-f` follows them. |
| `leopard doctor` | Check every server and site. |
| `leopard shell` | A shell with `php`, `wp`, `composer`, and `mysql` on PATH. |

`leopard help` lists everything.

## Settings

| Setting | Default | Change it |
| --- | --- | --- |
| Web port | 8080 | `leopard config set port 8081` |
| Database port | 3306 | `leopard config set db_port 3307` |
| Base URL | `http://localhost:<port>` | `leopard config set url http://100.1.2.3:8080` |

Run `leopard restart` after a change. On a remote machine or VM, set the base URL to
the address your browser uses: site URLs are built from it.

Database login for apps and tests: host `127.0.0.1`, user `dev`, password `dev`.
`leopard mysql` logs in as root.

## More

**PHP versions.** The installer's PHP (8.3 by default) is the default. Sites can use
8.3, 8.4, or 8.5 side by side: `leopard site create old --php 8.4` downloads it the
first time. Pick the default at install with `LEOPARD_PHP=8.5`.

**MySQL.** MariaDB is the default. `leopard db use mysql-8.4 --copy-data` switches
and copies every database. `leopard db use mariadb` switches back.

**Your own app.** Serve any folder at `/`: `leopard start ~/code/app/public`.

**Start at boot.** `leopard service install` adds a systemd unit. In a container
with no init, run `leopard run` as the main process.

**Upgrade.** Run the install line again. A running Leopard restarts on the new version.

**Uninstall.** `leopard stop`, then `rm -rf ~/.leopard ~/.local/bin/leopard`
(as root: `/var/lib/leopard` and `/usr/local/bin/leopard`).

**Limits.** Linux only (x86_64 and aarch64); on macOS or Windows, use a VM or WSL2. No
multisite or HTTPS yet. `php_value` lines in `.htaccess` do not work under PHP-FPM;
use `.user.ini`. As root, sites run as the owner of their folder.

## Build from source

```sh
./build.sh                                  # PHP 8.3; needs Docker on the build machine only
PHP_VERSIONS="8.3 8.5" ./build.sh           # one file per PHP version
./test.sh dist/leopard-php8.3-linux-$(uname -m)
```

The build packs official PHP and MySQL images and Debian's Apache and MariaDB,
with every library they load and their own glibc loader, so the file runs on any
Linux with the same CPU. `test.sh` proves it on bare Alpine, Debian, and Ubuntu,
and checks the agent contract end to end.
