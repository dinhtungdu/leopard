---
name: verify-leopard
description: "Builds the Leopard bundle from this checkout and drives it the way a user does: the `leopard` CLI in a throwaway Alpine container, plus the WordPress sites it serves in a real browser. Captures proof (command output, exit codes, screenshots, ARIA snapshots). Use to prove a change to leopard.sh, leopard.php, build.sh, or the stage scripts works end to end, to reproduce a bug, or before committing."
---

# Verify Leopard

The user surface is the `leopard` CLI (JSON and exit codes) and the HTTP sites it
serves. `scripts/lv` runs one built bundle in a disposable container per run and
saves proof under `dist/verify/<run>/` (gitignored). `test.sh` is the regression
suite; this skill is for proving one behavior on demand, with evidence.

Run `lv` by path: `L=.agents/skills/verify-leopard/scripts/lv`.

## 0. Docker

Building needs Docker (`build.sh` copies PHP, Apache, and MariaDB out of official
images). Driving uses it for isolation only. Check with `docker info`. In an Amp
orb, `.agents/resume` starts the daemon. If it is down, run `.agents/resume`.

## 1. Build

```sh
./build.sh          # first time, or after a stage-*.sh change: packs + core + file (~3 min)
./build.sh files    # after a change to leopard.sh, leopard.php, install.sh, or SKILL.md only (seconds)
./build.sh pack mysql 8.4 && ./build.sh files   # only for db-engine journeys
```

Output: `dist/leopard-php8.3-linux-$(uname -m)`. A packs change needs
`./build.sh files` afterward, because the file embeds the packs index.

## 2. Launch

```sh
$L up               # or: $L up debian:12-slim | ubuntu:24.04
```

It starts container `leopard-verify-<run>` (label `leopard-verify=<run>`), with:

- the bundle at `/usr/local/bin/leopard`, `dist/packs` at `/packs`, and `LEOPARD_PACKS_URL=file:///packs`
- host networking, a free web port from 18080 and db port from 13306, and `LEOPARD_URL=http://127.0.0.1:<port>`, so the container, `curl`, and the browser share one URL
- the volume `leopard-verify-wpcache` as the WP-CLI cache, so WordPress downloads once

It then runs `leopard start --json` (saved to `start.json`) and prints the env:
`LV_RUN`, `LV_C`, `LV_URL`, `LV_PORT`, `LV_DB_PORT`, `LV_PROOF`. Ready means
`lv up` exited 0. The container runs as root, so `LEOPARD_HOME` is
`/var/lib/leopard` and the default URL page is `$LV_URL/`.

## 3. Doctor

```sh
$L doctor
```

It is read-only. It checks that the container runs and carries this run's
label, that the bundle is unchanged since `up`, that the bundle is newer than
`leopard.sh`, `leopard.php`, and `skills/using-leopard/SKILL.md` (otherwise
rebuild and `up` again), that `$LV_URL/` answers, and that `leopard doctor
--json` is ok. Run it first, and again whenever a result looks wrong.

## 4. Drive

```sh
$L x 'leopard site list --json'                   # scratch, no proof
$L proof site-create 'cd /code/p && leopard site create --json'   # proof, returns the exit code
$L shot site-create "$LV_URL/p/"                  # screenshot + ARIA snapshot
```

- `x` and `proof` run `sh -c` inside the container with umask 022. Run every user command through `leopard ...`, never by touching `/var/lib/leopard` directly, except to read state for proof.
- The browser runs on the host (`agent-browser`, session `lv-$LV_RUN`). Use `AGENT_BROWSER_SESSION=lv-$LV_RUN agent-browser find label "Username or Email Address" fill admin` and similar, with ARIA names over refs. The WordPress login is `admin` / `password`.
- Recipes are in [features/README.md](features/README.md). Start from the feature file that matches the change. Drive every entry point it lists that the change touches.

## 5. Evidence

Each `proof` call writes `<time>.cmd`, `.out`, `.err`, and `.exit` under
`$LV_PROOF/<name>/`. Each `shot` writes `page.png` and `page.aria.txt`.
Before you claim a behavior works:

- Exercise the user path (`leopard` commands, HTTP requests, the browser). Never call `leopard.php` functions, and never edit state files to fake a precondition.
- Prove the action and the end state, with a second read-only view: `site info --json`, `site wp NAME ...`, `leopard mysql -N -e ...`, `curl`, or the browser.
- Check side effects: folders, symlinks, databases, and processes (`status --json`). For a failure path, check the exit code and `error.code`, and that nothing was left behind.
- View screenshots with `view_media` and an objective that states what you expect. Copy what the user should see into `.amp/in/artifacts/`.

## 6. Cleanup

```sh
$L down     # docker rm -f this run's container, closes its browser session
$L ls       # runs on this machine; clean only containers whose run you started
```

`down` keeps `dist/verify/<run>/` and the `leopard-verify-wpcache` volume. Run it
after every failed attempt too. Never `docker rm` by image or name pattern, and
never stop a Leopard you did not start.

## Without Docker (fallback)

When Docker is unavailable, test a released file (`gh release download -R
dinhtungdu/leopard`). It does not contain your changes, so it can reproduce
a bug but cannot prove a fix. Run it on the host with its own home and ports:
`LEOPARD_HOME=/tmp/lv-home LEOPARD_PORT=18080 LEOPARD_DB_PORT=13306
LEOPARD_URL=http://127.0.0.1:18080 ./leopard start --json`. Stop it with
`LEOPARD_HOME=/tmp/lv-home ./leopard stop`, then remove the home. Host mode tests
only this distro as a non-root user, and may leave `/tmp/leopard-*` sockets.

## Gotchas

- The orb's dockerd gives `docker exec` umask 0000, unlike a user's shell. `lv` sets umask 022 so runs match a user. If you bypass `lv`, set it yourself. To test umask 000 on purpose, set it inside the command.
- Assert which plugin is active by file (`plugin list --status=active --field=file`), not with `is-active NAME`. WordPress ships Hello Dolly as `hello.php`, so `is-active hello` passes whenever Hello Dolly is active.
- `show databases like "a_b"` treats `_` as a wildcard. Match database names exactly.
- `--host` sites listen on extra ports. With host networking they are reachable at `http://127.0.0.1:<port>`, but they can collide with services on this machine, so pass `--host=127.0.0.1:<free port>`.
- When `leopard.sh` or `leopard.php` changes after `up`, `doctor` fails on staleness. Rebuild with `./build.sh files`, then `down` and `up`. `build.sh` rewrites the file in place, so the running container already sees the new bytes, but its servers still run the old runtime until `leopard restart`. To prove an upgrade path, that `restart` is the journey.
