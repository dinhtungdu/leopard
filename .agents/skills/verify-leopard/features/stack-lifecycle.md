# Stack lifecycle

A user starts Apache, PHP-FPM, and the database with one command, reruns it safely, reads state from `status`, checks health with `doctor`, and stops everything. `start` also repairs a server that died. `run` keeps the stack in the foreground for containers.

## Sub-features

- `start-cold` starts the stack on the first run and saves the `LEOPARD_*` overrides.
- `start-rerun` is a no-op that still exits 0 while everything runs.
- `start-docroot` serves a folder at `/` with `start DIR`. Without DIR it serves the sites page.
- `start-busy-port` fails fast with `web_start_failed` when the port is taken and keeps the running stack.
- `status` reports ports, URLs, the database login, PHP masters, and sites.
- `doctor` exits 1 when a server is down.
- `heal` repairs a killed php-fpm master or web server with `start`.
- `stop`, `restart`, and `run` (heal in the foreground, stop on SIGTERM) round out the lifecycle.

## How to get to it (user POV)

- `leopard start [DIR] [--json]`, `stop`, `restart`, `status`, `doctor`, `run`
- The browser at `<url>/`, which lists the sites

## Driving it with lv

Preconditions:

- Baseline. `$LV_PROOF/start.json` holds the cold start from `lv up`.

- **Cold start proof.** `jq '.ok, .web.running, .web.url' $LV_PROOF/start.json` prints `true`, `true`, and `$LV_URL`.
- **Rerun.** `$L proof start-rerun 'leopard start --json'` exits 0, and `.web.pid` is unchanged in `status --json`.
- **Settings persist.** `$L proof status 'leopard status --json'`. `.web.url` is `$LV_URL` with no env set, and `.api` is 1.
- **Start page.** `$L shot status $LV_URL/`. The page says `Leopard is running` and lists the sites.
- **Heal.** `$L x 'kill -9 $(leopard status --json | leopard php -r "echo json_decode(stream_get_contents(STDIN))->fpm[0]->pid;")'`. Then `$L proof heal 'leopard doctor --json'` exits 1, `$L proof heal 'leopard start --json'` exits 0, and a site URL serves again.
- **Busy port.** `$L x 'leopard php -r "\$s = stream_socket_server(\"tcp://0.0.0.0:'$((LV_PORT+7))'\"); sleep(30);" >/dev/null 2>&1 &'`. Then `$L proof start-busy-port "LEOPARD_PORT=$((LV_PORT+7)) leopard start --json"` exits 1 with `error.code` set to `web_start_failed`. `$LV_URL/` still answers.
- **Stop.** `$L proof stop 'leopard stop --json'`. `.web.running` and `.db.running` are false, and `curl $LV_URL/` fails.

## Gotchas

- `status --json` reports `.ok` true even when a server is down. Assert `.web.running` and `.db.running`, or use `doctor`.
- A captured `start` must return, because no server may hold the caller's pipe. `proof` captures stdout, so a hang here is a real bug.
- `run` stays in the foreground. Start it in the background with `$L x 'leopard run >/tmp/run.log 2>&1 &'`, then stop it by its PID.
