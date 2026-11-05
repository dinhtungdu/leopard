# Leopard verification map

This directory holds the maintained recipes for verifying what Leopard users can see and do. Read this index, then use the feature file that matches the change. `L=.agents/skills/verify-leopard/scripts/lv` throughout.

## Baseline preconditions

- A fresh bundle exists in `dist/`, and `$L up` exited 0.
- `$L doctor` prints only `ok` lines.
- `LV_URL`, `LV_PORT`, and `LV_PROOF` come from `$L up` output or `dist/verify/<run>/env`.
- Never drive a container whose label is not this run's.

## Driving conventions

- Every command a user types runs through `$L proof <feature-id> '<command>'`. Use `$L x` only for fixtures and scratch reads.
- Add `--json` to every `leopard` command whose output you assert. Read fields with `jq` on the host, as in `$L x 'leopard status --json' | jq .web.port`.
- Create fixture plugins and themes under `/code/<unique-name>` in the container.
- HTTP checks run from the host with `curl` against `$LV_URL`, or in the browser through `$L shot` and `agent-browser` with `AGENT_BROWSER_SESSION=lv-$LV_RUN`.
- Delete the sites you create (`leopard site delete NAME`) when a later journey in the same run needs a clean list.

## Proof and skip reporting

- CLI proof is the `.cmd`, `.out`, `.err`, and `.exit` files that `proof` writes.
- Browser proof is a screenshot and an ARIA snapshot, inspected with `view_media`.
- Every mutation gets a second, read-only view: `site info`, `site wp`, `leopard mysql`, `status --json`, or HTTP.
- Error paths prove the exit code, `error.code`, and that nothing was left behind.
- Report an entry point you could not reach with its command and the missing precondition, such as no MySQL pack. Do not count it as verified through another path.

## Feature entry contract

Each feature file has an H1, one paragraph of user-visible behavior, and four H2s in this order: `Sub-features`, `How to get to it (user POV)`, `Driving it with lv`, and `Gotchas`.

## Features

- [Stack lifecycle](./stack-lifecycle.md) covers start, rerun, stop, restart, status, doctor, self-heal, and `run`.
- [Sites](./sites.md) covers site create from a plugin or theme folder or by name, `--host`, import, refusals, rollback, and delete.
- [PHP and Xdebug per site](./php-xdebug.md) covers per-site PHP from packs and per-site Xdebug with zero failed requests.
- [Logs](./logs.md) covers `logs mark`, `--since` cursors, the `--site` filter, and `--wait`.
- [Database engine](./db-engine.md) covers `db use mysql-8.4 --copy-data` and switching back.

Sites, Stack lifecycle, and Logs ran end to end through `lv` when this map was written. PHP and Xdebug and Database engine need extra packs. Their steps come from `test.sh` and have not run through `lv` yet. Fix them on first use.

Not mapped yet: `shell` and the tool shortcuts, `config set`, `skill setup|status|remove`, `update`, `service`, and `packs`. `test.sh` covers them. Add a file when a change touches one.
