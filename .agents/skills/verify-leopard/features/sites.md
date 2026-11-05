# Sites

A user creates a WordPress site with its own folder and database. The site is named after the plugin or theme folder they are in, or after a name they give. Leopard links their code without copying it and activates it. It serves the site at `<url>/NAME/` or on a host of its own, and deletes it without touching the linked code.

## Sub-features

- `site-folder` runs `site create` with no NAME inside a plugin or theme folder. It names, links, and activates the folder, and starts the stack if it is stopped.
- `site-named` runs `site create NAME --plugin [SLUG=]PATH --activate SLUG --theme PATH`.
- `site-host` uses `--host` to serve the site on the next free port, or `--host=HOST:PORT`.
- `site-import` imports a dump with `--import-sql FILE [--content ZIP] [--from-url URL]` and rewrites URLs.
- `site-refuse` refuses an existing name with exit 4, no NAME outside a plugin folder with exit 2, and a bad `--host` with exit 2 or 4.
- `site-rollback` makes a failed create remove what it made. A killed create leaves `state: creating`, and `site delete` cleans it up.
- `site-delete` removes the folder and database and keeps the linked source.

## How to get to it (user POV)

- `cd ~/code/my-plugin && leopard site create`
- `leopard site create dev --plugin ~/code/p --activate p --theme ~/code/t`
- `leopard site create shop --import-sql shop.sql --content wp-content.zip`
- `leopard site list | info NAME | delete NAME`
- The browser at `<url>/NAME/` and `<url>/NAME/wp-admin/`, with login `admin` / `password`

## Driving it with lv

Preconditions:

- Baseline from the README. No site named `hello-leopard` exists.

- **Fixture.** `$L x 'mkdir -p $LV_SCRATCH/code/hello-leopard && printf "<?php\n/*\n * Plugin Name: Hello Leopard\n */\nadd_action(\"wp_footer\", function () { echo \"<p id=hello-leopard>Hello from Leopard</p>\"; });\n" >$LV_SCRATCH/code/hello-leopard/hello-leopard.php'`
- **Create from the folder.** `$L proof site-folder 'cd $LV_SCRATCH/code/hello-leopard && leopard site create --json'`. Exit 0, `site.url` is `$LV_URL/hello-leopard`, `site.state` is `ready`, and `site.plugins[0].target` is `$LV_SCRATCH/code/hello-leopard`.
- **Second view.** `$L proof site-folder 'leopard site wp hello-leopard plugin list --fields=name,title,status --format=csv; readlink $LEOPARD_HOME/sites/hello-leopard/wp-content/plugins/hello-leopard; leopard mysql -N -e "show databases" | grep -x hello-leopard'`. The output shows `hello-leopard,"Hello Leopard",active`, the symlink `$LV_SCRATCH/code/hello-leopard`, and the database.
- **Served.** `curl -s $LV_URL/hello-leopard/ | grep 'Hello from Leopard'` matches.
- **Browser.** Open `$LV_URL/hello-leopard/wp-admin/plugins.php`. Then run `agent-browser find label "Username or Email Address" fill admin`, `agent-browser find label "Password" fill password`, `agent-browser find role button click --name "Log In"`, and `agent-browser wait --url "**/wp-admin/plugins.php"`. The wait returns, and the snapshot has the link `Deactivate Hello Leopard`. Save it with `agent-browser screenshot $LV_PROOF/site-folder/plugins.png`.
- **Refusals.** `$L proof site-refuse 'cd $LV_SCRATCH/code/hello-leopard && leopard site create --json'` exits 4. `$L proof site-refuse 'cd "$LV_SCRATCH" && leopard site create --json'` exits 2. The site still answers afterward.
- **Rollback.** `$L proof site-rollback 'leopard site create broken --activate no-such-plugin --json'` exits non-zero. Then `[ ! -e $LEOPARD_HOME/sites/broken ]` holds, and `leopard mysql -e "use broken"` fails.
- **Own host.** `$L proof site-host "leopard site create rooty --host=127.0.0.1:$(( LV_PORT + 50 )) --json"`. `site.url` is `http://127.0.0.1:<that port>`, `site.port` is that port, and `curl http://127.0.0.1:<that port>/wp-login.php` contains `user_login`.
- **Import.** `$L x 'leopard site wp hello-leopard db export $LV_SCRATCH/h.sql --quiet'`, then `$L proof site-import 'leopard site create copy --dir $LV_SCRATCH/copy --import-sql $LV_SCRATCH/h.sql --admin-password secret --json'`. `leopard site wp copy option get home` is `$LV_URL/copy`, and `user check-password admin secret` exits 0.
- **Delete.** `$L proof site-delete 'leopard site delete copy --json'`. `$LV_SCRATCH/copy` is gone, the `copy` database is gone, and `$LV_SCRATCH/code/hello-leopard/hello-leopard.php` still exists.

## Gotchas

- WordPress ships Hello Dolly as `hello.php`. A linked folder named `hello` must still win (`hello/hello.php` active). Check by file, not with `is-active hello`.
- The site name becomes the database name verbatim, hyphen included. Match it exactly, not with `LIKE`.
- `--host 127.0.0.1:8093` with a space is exit 2. The value must follow `=`.
- WordPress comes from wordpress.org the first time, then from the `leopard-verify-wpcache` volume. Offline, `create` uses the newest cached version.
- A `site create` holds the sites lock. Parallel creates wait, and they exit 6 after `LEOPARD_LOCK_WAIT`.
