---
name: verify-leopard
description: "Builds the Leopard bundle from this checkout and drives it the way a user does: the `leopard` CLI run as a normal user on this machine (or in a throwaway container for other distros), plus the WordPress sites it serves in a real browser. Captures proof (command output, exit codes, screenshots, ARIA snapshots). Use to prove a change to leopard.sh, leopard.php, build.sh, or the stage scripts works end to end, to reproduce a bug, or before committing."
---

# Verify Leopard

The user surface is the `leopard` CLI (JSON and exit codes) and the HTTP sites it
serves. `scripts/lv` runs one built bundle per run, the way a user does, and
saves proof under `dist/verify/<run>/` (gitignored). `test.sh` is the regression
suite; this skill is for proving one behavior on demand, with evidence.

Run `lv` by path: `L=.agents/skills/verify-leopard/scripts/lv`.

## 0. Docker

`build.sh` needs Docker: it copies PHP, Apache, and MariaDB out of official
images. Host runs do not need it; `--container` runs do. Check with `docker
info`. In an Amp orb, `.agents/resume` starts the daemon; if it is down, run
`.agents/resume`.

## 1. Build

```sh
./build.sh          # first time, or after a stage-*.sh change: packs + core + file (~3 min)
./build.sh files    # after a change to leopard.sh, leopard.php, install.sh, or SKILL.md only (seconds)
./build.sh pack mysql 8.4 && ./build.sh files   # only for db-engine journeys
```

Output: `dist/leopard-php8.3-linux-$(uname -m)`. A new pack needs
`./build.sh files` afterward, because the file embeds the packs index.

## 2. Launch

```sh
$L up                            # default: on this machine, as you
$L up --container                # alpine:3.20, as root
$L up --container ubuntu:24.04   # or debian:12-slim, any image
```

Both pick a free web port from 18080 and db port from 13306, set
`LEOPARD_URL=http://127.0.0.1:<port>`, run `leopard start --json` (saved to
`start.json`), and print the env: `LV_RUN`, `LV_MODE`, `LV_URL`, `LV_PORT`,
`LV_PROOF`. Ready means `lv up` exited 0.

- **Host (default).** It runs as the current user, like most people run Leopard.
  `LEOPARD_HOME`, `HOME`, and the fixture folder `LV_SCRATCH` all live under
  `/tmp/lv-<run>`, so the run never reads or writes your real `~`. `leopard` is on
  PATH, packs come from `dist/packs`, and the WP-CLI cache is shared in
  `~/.cache/leopard-verify/wp-cli`.
- **Container.** Use it for root, for other distros, or for a machine with no
  glibc (Alpine). The container is `leopard-verify-<run>`, labeled
  `leopard-verify=<run>`, with host networking. `LEOPARD_HOME` is
  `/var/lib/leopard`, `LV_SCRATCH` is `/scratch`, and the WP-CLI cache is the
  volume `leopard-verify-wpcache`.

## 3. Doctor

```sh
$L doctor
```

It is read-only. It checks that the run is ours (the container's label, or the
host home's link to the bundle), that the bundle is unchanged since `up`, that
the bundle is newer than `leopard.sh`, `leopard.php`, and
`skills/using-leopard/SKILL.md` (otherwise rebuild and `up` again), that
`$LV_URL/` answers, and that `leopard doctor --json` is ok. Run it first, and
again whenever a result looks wrong.

## 4. Drive

```sh
$L x 'leopard site list --json'                   # scratch, no proof
$L proof site-folder 'cd $LV_SCRATCH/code/p && leopard site create --json'   # proof, returns the exit code
$L shot site-folder "$LV_URL/p/"                  # screenshot + ARIA snapshot
```

- `x` and `proof` run `sh -c` in the run, from `$LV_SCRATCH`, with umask 022. `$LEOPARD_HOME` and `$LV_SCRATCH` are set in both modes. Write recipes with them, never with `/var/lib/leopard` or `/tmp`, so one recipe works in both modes.
- Run every user action through `leopard ...`. Read files under `$LEOPARD_HOME` only as proof.
- The browser runs on the host (`agent-browser`, session `lv-$LV_RUN`). Use `AGENT_BROWSER_SESSION=lv-$LV_RUN agent-browser find label "Username or Email Address" fill admin` and similar, with ARIA names over refs. The WordPress login is `admin` / `password`.
- Recipes are in [features/README.md](features/README.md). Start from the feature file that matches the change. Drive every entry point it lists that the change touches. If a change depends on root, the distro, or libc, run it with `--container` too.

## 5. Evidence

Each `proof` call writes `<time>.cmd`, `.out`, `.err`, and `.exit` under
`$LV_PROOF/<name>/`. Each `shot` writes `page.png` and `page.aria.txt`.
Before you claim a behavior works:

- Exercise the user path (`leopard` commands, HTTP requests, the browser). Never call `leopard.php` functions, and never edit state files to fake a precondition.
- Prove the action and the end state, with a second read-only view: `site info --json`, `site wp NAME ...`, `leopard mysql -N -e ...`, `curl`, or the browser.
- Check side effects: folders, symlinks, databases, and processes (`status --json`). For a failure path, check the exit code and `error.code`, and that nothing was left behind.
- View screenshots with `view_media` and an objective that states what you expect.

## 6. Cleanup

```sh
$L down     # host: leopard stop, then removes /tmp/lv-<run>; container: docker rm -f
$L ls       # every run: id, mode, up or gone, URL
```

`down` keeps `dist/verify/<run>/` and the WP-CLI caches. On the host, it kills
any process left behind that still names `/tmp/lv-<run>`, and reports it. That
is a bug worth reporting, because `stop` should leave none. Run `down` after
every failed attempt too. Never stop a Leopard or a container you did not start.

## Gotchas

- `--container` mode: the orb's dockerd gives `docker exec` umask 0000, unlike a user's shell. `lv` sets umask 022 in both modes. To test umask 000 on purpose, set it inside the command.
- Assert which plugin is active by file (`plugin list --status=active --field=file`), not with `is-active NAME`. WordPress ships Hello Dolly as `hello.php`, so `is-active hello` passes whenever Hello Dolly is active.
- `show databases like "a_b"` treats `_` as a wildcard. Match database names exactly.
- `--host` sites listen on extra ports, and both modes share this machine's ports, so pass `--host=127.0.0.1:<free port>`.
- When `leopard.sh` or `leopard.php` changes after `up`, `doctor` fails on staleness. Rebuild with `./build.sh files`, then `down` and `up`. `build.sh` rewrites the file in place, so the running instance already sees the new bytes, but its servers still run the old runtime until `leopard restart`. To prove an upgrade path, that `restart` is the journey.
- With no Docker at all, you cannot build. A released file (`gh release download -R dinhtungdu/leopard`) can reproduce a bug but cannot prove a fix. Drive it with `LV_BIN=/path/to/leopard-php8.3-linux-x86_64 $L up`.
