# Logs

A user marks a point in time, runs a request or test, and reads only the PHP errors and requests that came after it, for one site, as JSON.

## Sub-features

- `logs-mark` prints a cursor for "after now".
- `logs-since` returns only newer entries for `--since CURSOR|10m|ISO`, plus a new `cursor`.
- `logs-site` limits output to one site's PHP errors and requests with `--site NAME`.
- `logs-kind` filters by source with `--kind php,fpm,web,db,access` and `--level error`.
- `logs-wait` waits up to N seconds for the first new entry with `--wait N`.

## How to get to it (user POV)

- `cur=$(leopard logs mark)`, then `leopard logs --site NAME --since "$cur" --wait 5 --json`
- `leopard logs -n 50`, and `leopard logs -f` to follow

## Driving it with lv

Preconditions:

- Baseline, and a site `gb` exists.

- **Mark.** `cur=$($L x 'leopard logs mark')`. The output is a non-empty token.
- **Trigger.** `$L x 'printf "<?php trigger_error(\"only-gb\", E_USER_WARNING);" >/var/lib/leopard/sites/gb/w.php; printf "<?php trigger_error(\"only-root\", E_USER_WARNING);" >/var/lib/leopard/www/w.php'`, then `curl -s $LV_URL/gb/w.php $LV_URL/w.php >/dev/null`.
- **Read.** `$L proof logs-site "leopard logs --site gb --kind php --since '$cur' --wait 5 --json"`. `.entries | length` is 1, the message contains `only-gb`, and `.cursor` is set.
- **Access log.** `$L proof logs-kind "leopard logs --site gb --kind access --since '$cur' --json"` has an entry for `/gb/w.php`.

## Gotchas

- `logs` without `-f` prints and exits. Never use `-f` inside `proof`, because it never returns.
- A cursor is opaque base64url. Pass it as one quoted argument.
