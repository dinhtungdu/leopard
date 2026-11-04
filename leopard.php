<?php
// The half of the launcher that prints data and manages sites. leopard.sh
// calls it with the bundled PHP and passes the resolved settings as LP_* vars.
// Every command takes --json. Errors then print {"ok":false,"error":{...}}.
// Every JSON reply carries "api": API. Within one api number, fields are only
// ever added; a rename or removal bumps it.

const API = 1;
const EXIT_ERROR = 1, EXIT_USAGE = 2, EXIT_NOT_FOUND = 3, EXIT_EXISTS = 4, EXIT_NOT_RUNNING = 5, EXIT_BUSY = 6;
const DB_USER = 'dev', DB_PASSWORD = 'dev';
const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

final class LeopardError extends Exception
{
    public function __construct(public string $errorCode, string $message, public int $exit = EXIT_ERROR, public ?string $hint = null)
    {
        parent::__construct($message);
    }
}

$home = getenv('LP_HOME');
$runtime = getenv('LP_RUNTIME');
$sock = getenv('LP_SOCK') ?: "$home/run/mysqld.sock";
$baseUrl = rtrim(getenv('LP_URL') ?: 'http://localhost:8080', '/');
$args = array_slice($argv, 1);
$json = in_array('--json', $args, true);
$args = array_values(array_filter($args, fn($a) => $a !== '--json'));

function out(array $data, string $text): void
{
    global $json;
    if ($json) {
        echo json_encode(['ok' => true, 'api' => API] + $data, JSON_FLAGS), "\n";
    } else {
        echo $text;
    }
}

function alive(string $pidFile): ?int
{
    global $home;
    $pid = is_file($pidFile) ? (int) trim(file_get_contents($pidFile)) : 0;
    if ($pid <= 0) return null;
    $cmdline = @file_get_contents("/proc/$pid/cmdline");
    return $cmdline !== false && str_contains(str_replace("\0", ' ', $cmdline), "$home/") ? $pid : null;
}

// Site changes run one at a time. A full create can take minutes, so the wait
// is bounded by LEOPARD_LOCK_WAIT and then fails with exit 6, never hangs.
function sitesLock()
{
    global $home;
    $wait = ctype_digit((string) getenv('LEOPARD_LOCK_WAIT')) ? (int) getenv('LEOPARD_LOCK_WAIT') : 120;
    $lock = fopen("$home/.sites.lock", 'ce');
    $deadline = microtime(true) + $wait;
    while (!flock($lock, LOCK_EX | LOCK_NB)) {
        if (microtime(true) >= $deadline) {
            throw new LeopardError('busy', "Another site create or delete is still running after {$wait}s.", EXIT_BUSY,
                'Retry later, or set LEOPARD_LOCK_WAIT to wait longer.');
        }
        usleep(100000);
    }
    return $lock;
}

function versions(): array
{
    global $runtime;
    $v = [];
    foreach (file("$runtime/VERSIONS", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        [$name, $version] = explode(' ', $line, 2);
        $v[$name] = $version;
    }
    return $v;
}

function db(): mysqli
{
    global $sock;
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try {
        return new mysqli('localhost', 'root', '', '', 0, $sock);
    } catch (mysqli_sql_exception $e) {
        throw new LeopardError('not_running', 'MariaDB is not running: ' . $e->getMessage(), EXIT_NOT_RUNNING, 'Run `leopard start` first.');
    }
}

function dbExists(mysqli $db, string $name): bool
{
    $q = $db->prepare('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $q->bind_param('s', $name);
    $q->execute();
    return (bool) $q->get_result()->fetch_row();
}

function registryDir(): string
{
    global $home;
    return "$home/sites.d";
}

function readSite(string $name): ?array
{
    $file = registryDir() . "/$name.json";
    return is_file($file) ? json_decode(file_get_contents($file), true) : null;
}

function allSites(): array
{
    $sites = [];
    foreach (glob(registryDir() . '/*.json') ?: [] as $file) {
        $sites[] = json_decode(file_get_contents($file), true);
    }
    return $sites;
}

function validName(string $name): void
{
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $name)) {
        throw new LeopardError('bad_name', "Site names use letters, digits, '_' and '-', start with a letter or digit, and have at most 64 characters: '$name'", EXIT_USAGE);
    }
}

function needSite(string $name): array
{
    validName($name);
    return readSite($name) ?? throw new LeopardError('not_found', "No Leopard site named '$name'.", EXIT_NOT_FOUND, 'Run `leopard site list` to see sites.');
}

// Runs a command without a shell. Returns stdout, or throws with stderr.
// Output goes to temp files, not pipes: reading one full pipe while the child
// blocks writing the other would hang both.
function run(array $cmd, ?string $stdin = null, bool $check = true): array
{
    $out = tmpfile();
    $err = tmpfile();
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => $out, 2 => $err], $pipes);
    fwrite($pipes[0], $stdin ?? '');
    fclose($pipes[0]);
    $code = proc_close($p);
    rewind($out);
    rewind($err);
    $stdout = stream_get_contents($out);
    $stderr = stream_get_contents($err);
    if ($check && $code !== 0) {
        $what = basename($cmd[0]) . ' ' . ($cmd[1] ?? '') . ' ' . ($cmd[2] ?? '');
        throw new LeopardError('command_failed', trim($what) . " failed (exit $code): " . trim($stderr ?: $stdout));
    }
    return [$code, trim($stdout), trim($stderr)];
}

function wp(string $dir, array $args, ?string $stdin = null, bool $check = true): array
{
    global $runtime;
    return run(array_merge(["$runtime/bin/wp", "--path=$dir"], $args), $stdin, $check);
}

function reloadWeb(): bool
{
    global $home, $runtime;
    if (!alive("$home/run/apache.pid")) return false;
    run(["$runtime/bin/apache2", '-f', "$home/conf/httpd.conf", '-k', 'graceful']);
    return true;
}

function httpGet(string $url): ?int
{
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 3]);
    curl_exec($c);
    $code = curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    return $code ?: null;
}

function siteView(array $site): array
{
    $site['admin_url'] = $site['url'] . '/wp-admin/';
    $site['exists'] = is_dir($site['dir']);
    return $site;
}

function status(): void
{
    global $home, $runtime, $sock, $baseUrl;
    $web = alive("$home/run/apache.pid");
    $dbPid = alive("$home/run/mariadb.pid");
    $sites = array_map(fn($s) => ['name' => $s['name'], 'url' => $s['url'], 'dir' => $s['dir']], allSites());
    $data = [
        'home' => $home,
        'runtime' => $runtime,
        'versions' => versions(),
        'web' => ['running' => (bool) $web, 'pid' => $web, 'port' => (int) getenv('LP_PORT'), 'url' => $baseUrl, 'docroot' => getenv('LP_DOCROOT') ?: null],
        'db' => ['running' => (bool) $dbPid, 'pid' => $dbPid, 'host' => '127.0.0.1', 'port' => (int) getenv('LP_DB_PORT'), 'socket' => $sock, 'user' => DB_USER, 'password' => DB_PASSWORD, 'root_user' => 'root'],
        'xdebug_mode' => getenv('LP_XDEBUG'),
        'sites' => $sites,
    ];
    $text = sprintf("apache: %s\nmariadb: %s\n", $web ? "running (pid $web)" : 'stopped', $dbPid ? "running (pid $dbPid)" : 'stopped');
    if ($data['web']['docroot']) $text .= "docroot: {$data['web']['docroot']}\n";
    $text .= "web: $baseUrl\n";
    $text .= sprintf("db: 127.0.0.1:%d or %s (user %s, password %s; database wordpress)\n", $data['db']['port'], $sock, DB_USER, DB_PASSWORD);
    $text .= "xdebug: {$data['xdebug_mode']}\n";
    foreach ($sites as $s) $text .= "site {$s['name']}: {$s['url']}/\n";
    out($data, $text);
}

function config(): void
{
    $data = [
        'port' => (int) getenv('LP_PORT'),
        'db_port' => (int) getenv('LP_DB_PORT'),
        'url' => getenv('LP_URL'),
        'docroot' => getenv('LP_DOCROOT') ?: null,
        'xdebug' => getenv('LP_XDEBUG'),
    ];
    $text = '';
    foreach ($data as $k => $v) $text .= "$k=$v\n";
    out($data, $text);
}

function doctor(): void
{
    global $home, $baseUrl;
    $checks = [];
    $add = function (string $name, bool $ok, string $detail = '') use (&$checks) {
        $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    };
    $web = alive("$home/run/apache.pid");
    $dbPid = alive("$home/run/mariadb.pid");
    $add('apache running', (bool) $web, $web ? "pid $web" : 'run `leopard start`');
    $add('mariadb running', (bool) $dbPid, $dbPid ? "pid $dbPid" : 'run `leopard start`');
    if ($web) {
        $code = httpGet("$baseUrl/");
        $add("web answers at $baseUrl/", $code !== null, $code ? "HTTP $code" : 'no answer; check the url setting and the port');
    }
    $db = null;
    if ($dbPid) {
        try {
            $tcp = new mysqli('127.0.0.1', DB_USER, DB_PASSWORD, '', (int) getenv('LP_DB_PORT'));
            $add('db login over TCP as dev', true, '127.0.0.1:' . getenv('LP_DB_PORT'));
            $tcp->close();
            $db = db();
        } catch (Throwable $e) {
            $add('db login over TCP as dev', false, $e->getMessage());
        }
    }
    foreach (allSites() as $s) {
        $n = $s['name'];
        if (($s['state'] ?? 'ready') === 'creating') {
            $add("site $n: finished", false, "a create did not finish, or is running now; if none is running, run `leopard site delete $n`");
            continue;
        }
        $add("site $n: folder", is_file("{$s['dir']}/wp-config.php"), $s['dir']);
        if ($db) $add("site $n: database", dbExists($db, $s['db']), $s['db']);
        foreach ($s['plugins'] as $p) {
            $link = "{$s['dir']}/wp-content/plugins/{$p['name']}";
            $add("site $n: plugin {$p['name']}", is_link($link) && realpath($link) === $p['target'], $p['target']);
        }
        if ($web) {
            $code = httpGet("{$s['url']}/wp-login.php");
            $add("site $n: wp-login.php", $code === 200, $code ? "HTTP $code" : 'no answer');
        }
    }
    $failed = array_filter($checks, fn($c) => !$c['ok']);
    $text = '';
    foreach ($checks as $c) $text .= ($c['ok'] ? 'ok    ' : 'FAIL  ') . $c['name'] . ($c['detail'] ? "  ({$c['detail']})" : '') . "\n";
    global $json;
    if ($json) {
        echo json_encode(['ok' => !$failed, 'api' => API, 'checks' => $checks], JSON_FLAGS), "\n";
    } else {
        echo $text;
    }
    exit($failed ? EXIT_ERROR : 0);
}

// Parses `--flag value` and repeatable flags. Flags in $multi collect arrays.
function parseFlags(array $args, array $known, array $multi = []): array
{
    $flags = array_fill_keys($multi, []);
    $positional = [];
    for ($i = 0; $i < count($args); $i++) {
        $a = $args[$i];
        if (!str_starts_with($a, '--')) {
            $positional[] = $a;
            continue;
        }
        $key = substr($a, 2);
        $value = null;
        if (str_contains($key, '=')) [$key, $value] = explode('=', $key, 2);
        if (!in_array($key, $known, true) && !in_array($key, $multi, true)) {
            throw new LeopardError('usage', "Unknown option: --$key", EXIT_USAGE);
        }
        if ($value === null) {
            if (!isset($args[$i + 1])) throw new LeopardError('usage', "--$key needs a value", EXIT_USAGE);
            $value = $args[++$i];
        }
        if (in_array($key, $multi, true)) $flags[$key][] = $value; else $flags[$key] = $value;
    }
    return [$positional, $flags];
}

function newestCachedWordPress(): ?string
{
    $cache = getenv('WP_CLI_CACHE_DIR') ?: (getenv('HOME') . '/.wp-cli/cache');
    $best = null;
    foreach (glob("$cache/core/wordpress-*-en_US.tar.gz") ?: [] as $f) {
        if (preg_match('/wordpress-([0-9.]+)-en_US\.tar\.gz$/', $f, $m) && ($best === null || version_compare($m[1], $best, '>'))) {
            $best = $m[1];
        }
    }
    return $best;
}

function writeAtomic(string $file, string $content): void
{
    file_put_contents("$file.tmp", $content);
    rename("$file.tmp", $file);
}

function siteCreate(array $args): void
{
    global $home, $baseUrl, $sock;
    [$pos, $f] = parseFlags($args, ['dir', 'title', 'wp-version', 'import-sql', 'content', 'from-url', 'admin-user', 'admin-password', 'admin-email'], ['plugin', 'activate']);
    if (count($pos) !== 1) throw new LeopardError('usage', 'Usage: leopard site create NAME [options]. See `leopard help`.', EXIT_USAGE);
    $name = $pos[0];
    validName($name);
    $dir = $f['dir'] ?? "$home/sites/$name";
    if (!str_starts_with($dir, '/')) $dir = getcwd() . "/$dir";
    $dir = rtrim($dir, '/');
    if ($dir === '') throw new LeopardError('usage', '--dir cannot be /', EXIT_USAGE);
    $url = "$baseUrl/$name";
    $adminUser = $f['admin-user'] ?? 'admin';
    $adminPassword = $f['admin-password'] ?? 'password';
    if (isset($f['content']) && !isset($f['import-sql'])) throw new LeopardError('usage', '--content needs --import-sql', EXIT_USAGE);
    foreach (['import-sql', 'content'] as $k) {
        if (isset($f[$k]) && !is_file($f[$k])) throw new LeopardError('not_found', "--$k file not found: {$f[$k]}", EXIT_NOT_FOUND);
    }
    $plugins = [];
    foreach ($f['plugin'] as $spec) {
        [$pname, $path] = str_contains($spec, '=') ? explode('=', $spec, 2) : [null, $spec];
        $target = realpath($path);
        if ($target === false || !is_dir($target)) throw new LeopardError('not_found', "Plugin folder not found: $path", EXIT_NOT_FOUND);
        $plugins[] = ['name' => $pname ?? basename($target), 'target' => $target];
    }

    @mkdir(registryDir(), 0777, true);
    $lock = sitesLock();
    if ($old = readSite($name)) {
        throw ($old['state'] ?? 'ready') === 'creating'
            ? new LeopardError('exists', "An earlier create of '$name' did not finish.", EXIT_EXISTS, "Run `leopard site delete $name` to remove what it left, then create it again.")
            : new LeopardError('exists', "Site '$name' already exists.", EXIT_EXISTS, "Use `leopard site info $name`, or `leopard site delete $name` first.");
    }
    if (file_exists($dir) || is_link($dir)) throw new LeopardError('exists', "Folder already exists: $dir", EXIT_EXISTS, 'Leopard never adopts an existing folder. Pick another --dir.');
    $db = db();
    if (dbExists($db, $name)) throw new LeopardError('exists', "Database already exists: $name", EXIT_EXISTS, 'Leopard never adopts an existing database.');
    if (!alive("$home/run/apache.pid")) throw new LeopardError('not_running', 'Apache is not running.', EXIT_NOT_RUNNING, 'Run `leopard start` first.');

    // The record comes first, marked "creating". If this run is killed
    // outright, `site delete` can still find and remove what it made.
    $record = registryDir() . "/$name.json";
    writeAtomic($record, json_encode(['name' => $name, 'dir' => $dir, 'url' => $url, 'db' => $name, 'state' => 'creating', 'plugins' => []], JSON_FLAGS) . "\n");
    // Undo, newest first, everything this run made if a later step fails.
    $undo = [fn() => @unlink($record)];
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        foreach ([SIGINT, SIGTERM, SIGHUP] as $sig) {
            pcntl_signal($sig, function () { throw new LeopardError('interrupted', 'Interrupted. Removed what this run made.'); });
        }
    }
    try {
        @mkdir(dirname($dir), 0777, true);
        $undo[] = fn() => run(['rm', '-rf', '--', $dir], null, false);
        $wpVersion = $f['wp-version'] ?? null;
        $download = fn($v) => wp($dir, array_merge(['core', 'download', '--quiet'], $v ? ["--version=$v"] : []), null, false);
        [$code, , $err] = $download($wpVersion);
        if ($code !== 0 && $wpVersion === null && ($cached = newestCachedWordPress())) {
            // wordpress.org is unreachable; WP-CLI's cache still has a copy.
            [$code, , $err] = $download($cached);
        }
        if ($code !== 0) throw new LeopardError('download_failed', "Could not download WordPress: $err", EXIT_ERROR, 'Check network access to wordpress.org, or pass --wp-version for a version in the WP-CLI cache.');

        $db->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $undo[] = fn() => $db->query("DROP DATABASE IF EXISTS `$name`");
        wp($dir, ['config', 'create', "--dbname=$name", '--dbuser=' . DB_USER, '--dbpass=' . DB_PASSWORD,
            // The socket path never changes for a home; the TCP port can.
            "--dbhost=localhost:$sock", '--dbcharset=utf8mb4', '--extra-php'],
            "define( 'WP_ENVIRONMENT_TYPE', 'development' );\n");

        $alias = "$home/conf/sites/$name.conf";
        writeAtomic($alias, "Alias \"/$name\" \"$dir\"\n<Directory \"$dir\">\n  Options FollowSymLinks\n  AllowOverride All\n  Require all granted\n</Directory>\n");
        $undo[] = function () use ($alias) { @unlink($alias); reloadWeb(); };
        reloadWeb();

        if (isset($f['import-sql'])) {
            if (isset($f['content'])) {
                $zip = new ZipArchive();
                if ($zip->open($f['content']) !== true || !$zip->extractTo($dir)) throw new LeopardError('import_failed', "Could not unpack {$f['content']}");
                $zip->close();
            }
            wp($dir, ['db', 'import', realpath($f['import-sql'])]);
            $from = rtrim($f['from-url'] ?? wp($dir, ['option', 'get', 'home', '--skip-plugins', '--skip-themes'])[1], '/');
            if ($from !== $url) wp($dir, ['search-replace', $from, $url, '--all-tables', '--precise', '--skip-plugins', '--skip-themes', '--quiet']);
            if (wp($dir, ['user', 'get', $adminUser, '--field=ID', '--skip-plugins', '--skip-themes'], null, false)[0] === 0) {
                wp($dir, ['user', 'update', $adminUser, "--user_pass=$adminPassword", '--skip-email', '--skip-plugins', '--skip-themes']);
            } else {
                wp($dir, ['user', 'create', $adminUser, $f['admin-email'] ?? 'admin@example.test', '--role=administrator', "--user_pass=$adminPassword", '--skip-plugins', '--skip-themes']);
            }
            $source = 'import';
        } else {
            wp($dir, ['core', 'install', "--url=$url", '--title=' . ($f['title'] ?? $name), "--admin_user=$adminUser",
                "--admin_password=$adminPassword", '--admin_email=' . ($f['admin-email'] ?? 'admin@example.test'), '--skip-email']);
            wp($dir, ['rewrite', 'structure', '/%postname%/']);
            $source = 'fresh';
        }
        if (isset($f['title']) && $source === 'import') wp($dir, ['option', 'update', 'blogname', $f['title'], '--skip-plugins', '--skip-themes']);

        // Pretty permalinks under /<name>/. Written here, so it never depends
        // on WP-CLI detecting mod_rewrite.
        file_put_contents("$dir/.htaccess", "# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteBase /$name/\nRewriteRule ^index\\.php$ - [L]\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule . /$name/index.php [L]\n</IfModule>\n# END WordPress\n");

        foreach ($plugins as $p) {
            $link = "$dir/wp-content/plugins/{$p['name']}";
            if (file_exists($link) || is_link($link)) throw new LeopardError('exists', "Plugin folder already exists in the site: {$p['name']}", EXIT_EXISTS);
            symlink($p['target'], $link);
        }
        if ($f['activate']) wp($dir, array_merge(['plugin', 'activate'], $f['activate']));

        $version = preg_match("/\\\$wp_version = '([^']+)'/", file_get_contents("$dir/wp-includes/version.php"), $m) ? $m[1] : null;
        $site = [
            'name' => $name, 'dir' => $dir, 'url' => $url, 'db' => $name, 'source' => $source,
            'wp_version' => $version, 'plugins' => $plugins, 'admin' => ['user' => $adminUser, 'password' => $adminPassword],
            'state' => 'ready', 'created_at' => gmdate('c'),
        ];
        writeAtomic($record, json_encode($site, JSON_FLAGS) . "\n");
    } catch (Throwable $e) {
        foreach (array_reverse($undo) as $step) {
            try { $step(); } catch (Throwable) {}
        }
        throw $e;
    }
    $view = siteView($site);
    out(['site' => $view], "Created site $name ($source, WordPress $version)\nurl: $url/\nadmin: $url/wp-admin/ (user $adminUser, password $adminPassword)\ndir: $dir\n");
}

function siteDelete(array $args): void
{
    global $home;
    if (count($args) !== 1) throw new LeopardError('usage', 'Usage: leopard site delete NAME', EXIT_USAGE);
    $lock = sitesLock();
    $site = needSite($args[0]);
    $name = $site['name'];
    db()->query("DROP DATABASE IF EXISTS `{$site['db']}`");
    // rm does not follow the plugin symlinks, so linked checkouts stay intact.
    // A folder that became a symlink is not ours to empty.
    $folder = 'removed';
    if (is_link($site['dir'])) $folder = 'skipped: it is a symlink';
    elseif (is_dir($site['dir'])) run(['rm', '-rf', '--', $site['dir']]);
    else $folder = 'already gone';
    @unlink("$home/conf/sites/$name.conf");
    reloadWeb();
    unlink(registryDir() . "/$name.json");
    out(['deleted' => $name, 'db' => $site['db'], 'dir' => $site['dir'], 'folder' => $folder],
        "Deleted site $name: database {$site['db']}; folder {$site['dir']} $folder\n");
}

function main(array $args): void
{
    switch ($args[0] ?? '') {
        case 'status': status(); return;
        case 'config': config(); return;
        case 'doctor': doctor(); return;
        case 'version':
            $v = versions();
            out(['versions' => $v], implode('', array_map(fn($k, $x) => "$k $x\n", array_keys($v), $v)));
            return;
        case 'site':
            $sub = $args[1] ?? 'list';
            $rest = array_slice($args, 2);
            switch ($sub) {
                case 'create': siteCreate($rest); return;
                case 'delete': siteDelete($rest); return;
                case 'list':
                    $sites = array_map('siteView', allSites());
                    out(['sites' => $sites], $sites ? implode('', array_map(fn($s) => "{$s['name']}  {$s['url']}/  {$s['dir']}\n", $sites)) : "No sites. Make one with `leopard site create NAME`.\n");
                    return;
                case 'info':
                    if (count($rest) !== 1) throw new LeopardError('usage', 'Usage: leopard site info NAME', EXIT_USAGE);
                    $s = siteView(needSite($rest[0]));
                    out(['site' => $s], json_encode($s, JSON_FLAGS) . "\n");
                    return;
                case 'path':
                    if (count($rest) !== 1) throw new LeopardError('usage', 'Usage: leopard site path NAME', EXIT_USAGE);
                    $dir = needSite($rest[0])['dir'];
                    out(['dir' => $dir], "$dir\n");
                    return;
            }
            throw new LeopardError('usage', "Unknown site command: $sub. Use create, list, info, path, delete, or wp.", EXIT_USAGE);
    }
    throw new LeopardError('usage', 'Unknown command: ' . ($args[0] ?? ''), EXIT_USAGE);
}

try {
    main($args);
} catch (LeopardError $e) {
    if ($json) {
        echo json_encode(['ok' => false, 'api' => API, 'error' => array_filter(['code' => $e->errorCode, 'message' => $e->getMessage(), 'hint' => $e->hint])], JSON_FLAGS), "\n";
    } else {
        fwrite(STDERR, 'leopard: ' . $e->getMessage() . "\n" . ($e->hint ? "hint: {$e->hint}\n" : ''));
    }
    exit($e->exit);
} catch (Throwable $e) {
    $msg = get_class($e) . ': ' . $e->getMessage();
    if ($json) echo json_encode(['ok' => false, 'api' => API, 'error' => ['code' => 'internal', 'message' => $msg]], JSON_FLAGS), "\n";
    else fwrite(STDERR, "leopard: $msg\n");
    exit(EXIT_ERROR);
}
