# Database engine

A user switches from MariaDB to MySQL 8.4 with every database copied, keeps sites working on the same port and socket, and switches back with MariaDB's data untouched.

## Sub-features

- `db-list` shows installed and available engines with `db list [--available]`.
- `db-use-copy` runs `db use mysql-8.4 --copy-data`, which fetches the pack, copies every database, and keeps sites working.
- `db-use-back` runs `db use mariadb`, which brings back the original data.
- `db-prune` uses `db prune ENGINE` to delete an inactive engine's data. Run it only when the change touches it.

## How to get to it (user POV)

- `leopard db use mysql-8.4 --copy-data --json`, then `leopard db use mariadb --json`

## Driving it with lv

Preconditions:

- Baseline, with a MySQL pack: `./build.sh pack mysql 8.4 && ./build.sh files`, then `$L up` again. Without it, report the feature as not reachable.
- A site `gb` with a marker: `$L x 'leopard site create gb --json >/dev/null && leopard site wp gb option update blogname MarkerDB'`.

- **Switch.** `$L proof db-use-copy 'leopard db use mysql-8.4 --copy-data --json'` exits 0. `leopard mysql -N -e "select version()"` starts with `8.4`.
- **Sites survive.** `$L proof db-use-copy 'leopard site wp gb option get blogname'` prints `MarkerDB`, and `curl $LV_URL/gb/wp-login.php` contains `user_login`.
- **Back.** `$L proof db-use-back 'leopard db use mariadb --json'`. `select version()` contains `MariaDB`, and `blogname` is still `MarkerDB`.

## Gotchas

- Without `--copy-data`, the new engine starts with only the `wordpress` database and the `dev` user, so sites break. That is expected, not a bug.
- `db prune` deletes data. Never run it as cleanup.
