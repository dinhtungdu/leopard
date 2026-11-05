<?php
// Leopard's control plane. leopard.sh runs it on the bundled PHP for every
// command except the tool shortcuts (php, wp, composer, mysql, shell).
//
// The wanted state lives in files: conf/leopard.json (settings) and
// sites.d/<name>.json (sites). Every command that changes something writes
// those files, then makes the running servers match them and exits. No part
// of Leopard stays running between commands, except `leopard run`.
//
// Servers: one Apache (event MPM), one database, and one PHP-FPM master for
// each (PHP pack, Xdebug mode) that some site uses. Xdebug fixes its mode
// when a process starts, so a different mode needs a different master. Each
// site is a pool in its master, on its own socket. A site that moves to
// another master gets a new socket, and Apache moves to it before the old
// pool goes away, so no request fails during the switch.
//
// Every --json reply is one JSON document with "api": API. Within one api
// number, fields are only added; a rename or removal bumps it.

const API = 1;
const EXIT_ERROR = 1, EXIT_USAGE = 2, EXIT_NOT_FOUND = 3, EXIT_EXISTS = 4, EXIT_NOT_RUNNING = 5, EXIT_BUSY = 6;
const DB_USER = 'dev', DB_PASSWORD = 'dev';
const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
const XDEBUG_MODES = ['off', 'develop', 'coverage', 'debug', 'gcstats', 'profile', 'trace'];

final class LeopardError extends Exception
{
    public function __construct(public string $errorCode, string $message, public int $exit = EXIT_ERROR,
        public ?string $hint = null, public ?string $log = null)
    {
        parent::__construct($message);
    }
}

$H = getenv('LEOPARD_HOME');
$R = getenv('LP_RUNTIME');
$sock = getenv('LP_SOCK');
$args = array_slice($argv, 1);
// Strip --json from Leopard's own arguments, but not from a command passed
// through to WP-CLI or PHP (`site wp NAME ...`, `site php NAME ...`).
$own = ($args[0] ?? '') === 'site' && in_array($args[1] ?? '', ['wp', 'php'], true) ? 3 : count($args);
$json = in_array('--json', array_slice($args, 0, $own), true);
$args = array_merge(array_values(array_filter(array_slice($args, 0, $own), fn($a) => $a !== '--json')), array_slice($args, $own));

function out(array $data, string $text): void
{
    global $json;
    if ($json) echo json_encode(['ok' => true, 'api' => API] + $data, JSON_FLAGS), "\n";
    else echo $text;
}

function writeAtomic(string $file, string $content): void
{
    file_put_contents("$file.tmp" . getmypid(), $content);
    rename("$file.tmp" . getmypid(), $file);
}

// Writes the file only if its content changed. Returns true if it did.
function writeIfChanged(string $file, string $content): bool
{
    if (@file_get_contents($file) === $content) return false;
    @mkdir(dirname($file), 0777, true);
    writeAtomic($file, $content);
    return true;
}

function readJson(string $file): ?array
{
    $s = @file_get_contents($file);
    return $s === false ? null : json_decode($s, true);
}

// ---------------------------------------------------------------- settings

// Saved settings. Homes made by Leopard 0.1 kept them in a shell file.
function savedSettings(): array
{
    global $H;
    $s = readJson("$H/conf/leopard.json");
    if ($s !== null) return $s;
    $s = [];
    foreach (@file("$H/conf/leopard.conf", FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match("/^cfg_(\w+)='(.*)'$/", $line, $m) && $m[2] !== '') $s[$m[1]] = $m[2];
    }
    if (!isset($s['docroot']) && is_file("$H/conf/docroot")) $s['docroot'] = trim(file_get_contents("$H/conf/docroot"));
    foreach (['port', 'db_port'] as $k) if (isset($s[$k])) $s[$k] = (int) $s[$k];
    return $s;
}

function saveSettings(array $s): void
{
    global $H;
    writeAtomic("$H/conf/leopard.json", json_encode(array_filter($s, fn($v) => $v !== null && $v !== ''), JSON_FLAGS) . "\n");
}

// The settings in effect: the environment first, then saved, then defaults.
function cfg(): array
{
    $s = savedSettings();
    $port = (int) (getenv('LEOPARD_PORT') ?: ($s['port'] ?? 8080));
    $urlSet = getenv('LEOPARD_URL') ?: ($s['url'] ?? '');
    return [
        'port' => $port,
        'db_port' => (int) (getenv('LEOPARD_DB_PORT') ?: ($s['db_port'] ?? 3306)),
        'url_set' => rtrim($urlSet, '/'),
        'url' => rtrim($urlSet ?: "http://localhost:$port", '/'),
        'docroot' => $s['docroot'] ?? null,
        'xdebug' => $s['xdebug'] ?? 'off',
        'php' => $s['php'] ?? null,
        'db' => $s['db'] ?? 'mariadb',
        'bin' => getenv('LEOPARD_BIN') ?: ($s['bin'] ?? null),
    ];
}

function lockWait(): int
{
    $w = getenv('LEOPARD_LOCK_WAIT');
    return $w !== false && ctype_digit($w) ? (int) $w : 120;
}

// Takes an exclusive lock, waiting at most LEOPARD_LOCK_WAIT seconds, then
// fails with exit 6. The file is opened close-on-exec, so servers that
// Leopard starts never hold it.
function lock(string $file, string $what)
{
    $wait = lockWait();
    $h = fopen($file, 'ce');
    $deadline = microtime(true) + $wait;
    while (!flock($h, LOCK_EX | LOCK_NB)) {
        if (microtime(true) >= $deadline) {
            throw new LeopardError('busy', "Another $what is still running after {$wait}s.", EXIT_BUSY,
                'Retry in a moment, or set LEOPARD_LOCK_WAIT to wait longer.');
        }
        usleep(100000);
    }
    return $h;
}

function stateLock() { global $H; return lock("$H/.lock", 'start, stop, or site change'); }
function sitesLock() { global $H; return lock("$H/.sites.lock", 'site create or delete'); }

// ---------------------------------------------------------------- packs

function packsDir(): string { global $H; return "$H/packs"; }

function packMeta(string $id): ?array
{
    $m = readJson(packsDir() . "/$id/pack.json");
    return $m ? $m + ['id' => $id, 'dir' => packsDir() . "/$id"] : null;
}

function installedPacks(string $kind): array
{
    $packs = [];
    foreach (glob(packsDir() . "/$kind-*", GLOB_ONLYDIR) ?: [] as $d) {
        if ($m = packMeta(basename($d))) $packs[] = $m;
    }
    usort($packs, fn($a, $b) => version_compare($b['version'], $a['version']));
    return $packs;
}

function controlPhp(): string
{
    global $R;
    return trim(file_get_contents("$R/CONTROL_PHP"));
}

// The pack id of the default PHP: the saved one if it is installed, else
// the one this Leopard file came with.
function defaultPhp(): string
{
    $id = cfg()['php'];
    return $id && packMeta($id) ? $id : controlPhp();
}

// "8.4", "8.4.15", or a pack id -> the newest installed pack that matches.
function findPhp(string $spec): ?array
{
    if ($m = packMeta($spec)) return $m['kind'] === 'php' ? $m : null;
    foreach (installedPacks('php') as $p) {
        if ($p['line'] === $spec || $p['version'] === $spec) return $p;
    }
    return null;
}

function packIndex(): array
{
    global $R;
    return readJson("$R/packs.json") ?? ['packs' => []];
}

function indexEntry(string $kind, string $spec): ?array
{
    foreach (packIndex()['packs'] as $p) {
        if ($p['kind'] === $kind && ($p['line'] === $spec || $p['version'] === $spec || $p['id'] === $spec)) return $p;
    }
    return null;
}

// A PHP pack for a version: installed, or fetched from the release.
function needPhp(string $spec): array
{
    if ($p = findPhp($spec)) return $p;
    $entry = indexEntry('php', $spec);
    if (!$entry) {
        $known = implode(', ', array_column(array_filter(packIndex()['packs'], fn($p) => $p['kind'] === 'php'), 'line'));
        throw new LeopardError('pack_missing', "PHP $spec is not installed, and this Leopard release has no pack for it.", EXIT_NOT_FOUND,
            "Versions this release knows: $known. Run `leopard php list --available`.");
    }
    return installPack($entry);
}

// Fetches a pack from the release (or LEOPARD_PACKS_URL), checks its sha256
// against the index inside this Leopard file, and unpacks it. Safe to retry:
// a download resumes, and a half-unpacked pack never appears.
function installPack(array $entry, ?string $file = null): array
{
    global $H;
    if ($m = packMeta($entry['id'])) return $m;
    @mkdir(packsDir() . '/.dl', 0777, true);
    $lock = lock(packsDir() . "/.lock.{$entry['id']}", "install of {$entry['id']}");
    if ($m = packMeta($entry['id'])) return $m;
    if ($file === null) {
        $base = getenv('LEOPARD_PACKS_URL') ?: (packIndex()['base_url'] ?? '');
        if ($base === '') {
            throw new LeopardError('pack_unavailable', "This Leopard file is a development build, so it cannot download {$entry['file']}.", EXIT_ERROR,
                'Install it from a file with `leopard packs add FILE`, or set LEOPARD_PACKS_URL to a folder or URL that has it.');
        }
        $file = packsDir() . "/.dl/{$entry['sha256']}.part";
        $url = rtrim($base, '/') . '/' . $entry['file'];
        $fh = fopen($file, 'a');
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_FAILONERROR => true,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_LOW_SPEED_LIMIT => 1024, CURLOPT_LOW_SPEED_TIME => 30,
            CURLOPT_RESUME_FROM => filesize($file)]);
        if (posix_isatty(STDERR)) {
            curl_setopt($c, CURLOPT_NOPROGRESS, false);
        }
        $ok = curl_exec($c);
        $err = curl_error($c);
        curl_close($c);
        fclose($fh);
        if (!$ok && filesize($file) < $entry['size']) {
            throw new LeopardError('download_failed', "Could not download $url: $err", EXIT_ERROR, 'Run the same command again; the download resumes.');
        }
    }
    if (hash_file('sha256', $file) !== $entry['sha256']) {
        if (str_ends_with($file, '.part')) @unlink($file);
        throw new LeopardError('verify_failed', "The pack does not match the sha256 this Leopard file expects for {$entry['id']}.", EXIT_ERROR,
            'The file is damaged or not the one this release built. Nothing was installed.');
    }
    $tmp = packsDir() . '/.tmp.' . getmypid();
    run(['rm', '-rf', $tmp]);
    mkdir($tmp);
    run(['tar', '-xzf', $file, '-C', $tmp]);
    $meta = readJson("$tmp/pack.json");
    if (($meta['kind'] ?? '') !== $entry['kind'] || ($meta['version'] ?? '') !== $entry['version']) {
        run(['rm', '-rf', $tmp]);
        throw new LeopardError('verify_failed', "The pack's pack.json does not match {$entry['id']}.");
    }
    rename($tmp, packsDir() . "/{$entry['id']}");
    if (str_ends_with($file, '.part')) @unlink($file);
    flock($lock, LOCK_UN);
    @unlink(packsDir() . "/.lock.{$entry['id']}");
    // The new PHP needs its settings before anything runs on it.
    prepare();
    return packMeta($entry['id']);
}

// ---------------------------------------------------------------- processes

// Field 3 is the state, field 22 the start time. A zombie counts as dead.
function procStart(int $pid): ?string
{
    if ($pid <= 0) return null;
    $s = @file_get_contents("/proc/$pid/stat");
    if ($s === false) return null;
    $f = explode(' ', substr($s, strrpos($s, ')') + 2));
    return in_array($f[0], ['Z', 'X'], true) ? null : $f[19];
}

// A process record is alive if its pid still runs and started at the same
// time. That rejects pids that were reused after a crash or a reboot.
function alive(?array $p): bool
{
    return $p && isset($p['pid'], $p['start']) && procStart($p['pid']) === $p['start'];
}

// True if the process runs a binary from this home. Every server Leopard
// starts runs through a loader inside the home, and the loader's path is the
// first word of its command line. /proc/<pid>/exe is not enough: as root in
// a container without ptrace rights, it cannot be read for workers that
// switched to another user.
function ours(int $pid): bool
{
    global $H;
    $home = (realpath($H) ?: $H) . '/';
    $exe = @readlink("/proc/$pid/exe");
    if ($exe !== false) return str_starts_with($exe, $home);
    return str_starts_with((string) @file_get_contents("/proc/$pid/cmdline"), $home);
}

// Starts a server in its own session, detached from this command: stdin is
// /dev/null and stdout and stderr go to $log. Anything left open by the
// caller on fds 3-9 is closed, so `out=$(leopard start)` never waits on a
// server that kept its pipe.
function spawn(array $cmd, string $log, array $env = []): array
{
    $pid = pcntl_fork();
    if ($pid === -1) throw new LeopardError('spawn_failed', 'Could not start a process.');
    if ($pid === 0) {
        posix_setsid();
        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);
        $in = fopen('/dev/null', 'r');
        $o = fopen($log, 'a');
        $e = fopen($log, 'a');
        pcntl_exec('/bin/sh', ['-c', 'exec 3>&- 4>&- 5>&- 6>&- 7>&- 8>&- 9>&- "$0" "$@"', ...$cmd], $env + ['TZ' => 'UTC'] + childEnv(false));
        exit(127);
    }
    for ($i = 0; $i < 100 && ($start = procStart($pid)) === null; $i++) usleep(1000);
    return ['pid' => $pid, 'start' => $start];
}

// Every process in the group that a server started, if it runs our binaries.
// After a crash, FPM workers outlive their master and keep its socket.
function killGroup(?array $p): void
{
    if (!$p || empty($p['pid'])) return;
    $left = [];
    foreach (glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: [] as $d) {
        $pid = (int) basename($d);
        if ($pid !== getmypid() && @posix_getpgid($pid) === $p['pid'] && ours($pid)) {
            posix_kill($pid, SIGKILL);
            $left[] = $pid;
        }
    }
    for ($i = 0; $i < 50 && array_filter($left, fn($x) => procStart($x) !== null); $i++) usleep(20000);
}

function stopProc(?array $p, int $signal, float $timeout): void
{
    if (alive($p)) {
        posix_kill($p['pid'], $signal);
        $deadline = microtime(true) + $timeout;
        while (alive($p) && microtime(true) < $deadline) usleep(50000);
        if (alive($p)) posix_kill($p['pid'], SIGKILL);
        for ($i = 0; $i < 40 && alive($p); $i++) usleep(50000);
    }
    killGroup($p);
}

// The environment for a child process. The launcher runs this PHP with
// XDEBUG_MODE=off and keeps the caller's value in LP_XDEBUG_MODE. Servers
// get neither: their Xdebug mode comes from their own ini. Tools get the
// caller's value back.
function childEnv(bool $tool): array
{
    $env = getenv();
    $user = $env['LP_XDEBUG_MODE'] ?? null;
    foreach (array_keys($env) as $k) if ($k === 'XDEBUG_MODE' || str_starts_with($k, 'LP_')) unset($env[$k]);
    if ($tool && $user !== null) $env['XDEBUG_MODE'] = $user;
    return $env;
}

function tailOf(string $file, int $lines = 5): string
{
    $all = @file($file, FILE_IGNORE_NEW_LINES) ?: [];
    return implode("\n", array_slice($all, -$lines));
}

// Runs a command without a shell. Returns [exit code, stdout, stderr], or
// throws with stderr. Output goes to temp files, not pipes: reading one full
// pipe while the child blocks writing the other would hang both.
function run(array $cmd, ?string $stdin = null, bool $check = true, array $env = []): array
{
    $out = tmpfile();
    $err = tmpfile();
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => $out, 2 => $err], $pipes, null, $env ? $env + getenv() : null);
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

function portFree(string $addr, int $port): bool
{
    $s = @stream_socket_server("tcp://$addr:$port");
    if (!$s) return false;
    fclose($s);
    return true;
}

function portAnswers(int $port): bool
{
    $s = @fsockopen('127.0.0.1', $port, $no, $str, 0.2);
    if (!$s) return false;
    fclose($s);
    return true;
}

// ---------------------------------------------------------------- state

// What runs now: run/state.json. Homes from Leopard 0.1 have only pid files.
function loadState(): array
{
    global $H;
    $s = readJson("$H/run/state.json");
    if ($s !== null) return $s + ['fpm' => [], 'pools' => []];
    $s = ['web' => null, 'db' => null, 'fpm' => [], 'pools' => []];
    foreach (['web' => 'apache', 'db' => 'mariadb'] as $k => $name) {
        $pid = (int) @file_get_contents("$H/run/$name.pid");
        $cmd = @file_get_contents("/proc/$pid/cmdline");
        if ($pid > 0 && $cmd !== false && str_contains(str_replace("\0", ' ', $cmd), "$H/") && ($start = procStart($pid))) {
            $port = (int) @file_get_contents("$H/run/" . ($k === 'web' ? 'web' : 'db') . '.port');
            $s[$k] = ['pid' => $pid, 'start' => $start, 'port' => $port ?: null, 'legacy' => true, 'engine' => 'mariadb'];
        }
    }
    return $s;
}

function saveState(array $s): void
{
    global $H;
    writeAtomic("$H/run/state.json", json_encode($s, JSON_FLAGS) . "\n");
}

// ---------------------------------------------------------------- sites

function registryDir(): string { global $H; return "$H/sites.d"; }

function readSite(string $name): ?array { return readJson(registryDir() . "/$name.json"); }

function allSites(): array
{
    $sites = [];
    foreach (glob(registryDir() . '/*.json') ?: [] as $file) {
        if ($s = readJson($file)) $sites[] = $s;
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

// "debug,profile" -> sorted, checked. "" and "off" -> "off".
function normMode(string $mode): string
{
    $modes = array_values(array_unique(array_filter(array_map('trim', explode(',', $mode)))));
    foreach ($modes as $m) {
        if (!in_array($m, XDEBUG_MODES, true)) {
            throw new LeopardError('usage', "Unknown Xdebug mode: $m. Use " . implode(', ', XDEBUG_MODES) . ' (comma-separated).', EXIT_USAGE);
        }
    }
    $modes = array_diff($modes, ['off']);
    sort($modes);
    return $modes ? implode(',', $modes) : 'off';
}

// The PHP pack and Xdebug mode a site runs with. A site without its own
// setting follows the defaults.
function sitePhp(array $site): array
{
    $id = $site['php'] ?? null;
    return $id && packMeta($id) ? packMeta($id) : packMeta(defaultPhp());
}

function siteXdebug(array $site): string
{
    return $site['xdebug'] ?? cfg()['xdebug'];
}

function siteLogDir(string $name): string { global $H; return "$H/logs/sites/$name"; }

function siteView(array $site): array
{
    $php = sitePhp($site);
    $site['admin_url'] = $site['url'] . '/wp-admin/';
    $site['exists'] = is_dir($site['dir']);
    $site['php'] = $site['php'] ?? null;
    $site['xdebug'] = $site['xdebug'] ?? null;
    $site['runs'] = ['php' => $php['version'], 'php_pack' => $php['id'], 'xdebug' => siteXdebug($site)];
    $site['logs'] = ['php' => siteLogDir($site['name']) . '/php-error.log'];
    return $site;
}

// ---------------------------------------------------------------- config files

function phpSettings(string $errorLog): string
{
    global $H, $sock;
    return <<<INI
memory_limit = 1024M
upload_max_filesize = 128M
post_max_size = 128M
max_execution_time = 300
max_input_vars = 5000
display_errors = On
error_reporting = E_ALL
log_errors = On
error_log = "$errorLog"
date.timezone = UTC
mysqli.default_socket = "$sock"
pdo_mysql.default_socket = "$sock"
; Code edits show up on the next request.
opcache.revalidate_freq = 0
xdebug.discover_client_host = 1
xdebug.output_dir = "$H/logs/xdebug"

INI;
}

// Settings files that do not depend on which servers run: one CLI ini per
// PHP pack, the MariaDB client config, the WP-CLI config, and the link to
// the default PHP. Rewritten by every command, because they hold paths into
// the current runtime.
function prepare(): void
{
    global $H, $sock;
    foreach (['conf/php', 'conf/fpm', 'conf/sites', 'logs/sites', 'logs/xdebug', 'run/fpm', 'sites.d'] as $d) @mkdir("$H/$d", 0777, true);
    $mode = cfg()['xdebug'];
    foreach (installedPacks('php') as $p) {
        writeIfChanged("$H/conf/php/cli-{$p['id']}.ini", file_get_contents("{$p['dir']}/php.ini") . "zend_extension = xdebug\n"
            . phpSettings("$H/logs/php-error.log") . "xdebug.mode = \"$mode\"\n"
            // The CLI writes errors to stderr, so warnings never mix into
            // output that scripts read, such as `url=$(wp option get home)`.
            . "display_errors = stderr\nerror_reporting = E_ALL & ~E_DEPRECATED\n");
    }
    $default = defaultPhp();
    if (@readlink("$H/packs/default") !== $default) {
        @symlink($default, "$H/packs/default.tmp");
        rename("$H/packs/default.tmp", "$H/packs/default");
    }
    // The MariaDB client reads $MYSQL_HOME/my.cnf, so plain `mysql` logs in as
    // root. The server listens only on 127.0.0.1, so skip the TLS certificate
    // check that MariaDB 11 clients warn about on every import and dump.
    writeIfChanged("$H/conf/my.cnf", "[client]\nuser = root\nsocket = \"$sock\"\nskip-ssl-verify-server-cert\n");
    // WP-CLI cannot see Apache's modules from the command line. Tell it about
    // mod_rewrite so `wp rewrite flush --hard` writes .htaccess. Keep the
    // user's own global WP-CLI config.
    $user = getenv('HOME') . '/.wp-cli/config.yml';
    writeIfChanged("$H/conf/wp-cli.yml", "apache_modules: [mod_rewrite]\n" . (is_file($user) ? "_:\n  inherit: $user\n" : ''));
}

function isRoot(): bool { return posix_geteuid() === 0; }

// As root, PHP runs as the owner of the site folder, or as nobody if root
// owns it. Otherwise it runs as the user who runs Leopard.
function poolUser(string $dir): array
{
    $st = @stat(is_dir($dir) ? $dir : dirname($dir));
    $u = $st && $st['uid'] !== 0 ? $st['uid'] : 65534;
    $g = $st && $st['gid'] !== 0 ? $st['gid'] : 65534;
    return [$u, $g];
}

function socketPath(string $pool, int $gen): string
{
    global $H;
    $p = "$H/run/fpm/$pool.$gen.sock";
    // Unix socket paths are limited to about 107 bytes.
    if (strlen($p) > 100) {
        $dir = '/tmp/leopard-' . posix_geteuid() . '-' . hash('crc32b', $H);
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        $p = "$dir/" . hash('crc32b', $pool) . ".$gen.sock";
    }
    return $p;
}

// The pools that should run, keyed by pool name: one per site, and `_root`
// for the docroot. Each gets a socket. A pool keeps its socket while its
// master stays the same, and gets a new one when it moves.
function wantedPools(array $state, string $docroot): array
{
    $want = [['_root', $docroot, packMeta(defaultPhp()), cfg()['xdebug'], null]];
    foreach (allSites() as $s) $want[] = [$s['name'], $s['dir'], sitePhp($s), siteXdebug($s), $s['name']];
    $pools = [];
    foreach ($want as [$name, $dir, $pack, $mode, $site]) {
        $key = $pack['id'] . '.' . str_replace(',', '+', $mode);
        $old = $state['pools'][$name] ?? null;
        $gen = $old && $old['key'] === $key ? $old['gen'] : ($old['gen'] ?? 0) + 1;
        $pools[$name] = ['name' => $name, 'key' => $key, 'pack' => $pack['id'], 'mode' => $mode, 'dir' => $dir,
            'site' => $site, 'gen' => $gen, 'sock' => socketPath($name, $gen)];
    }
    return $pools;
}

// A master key is "<pack id>.<mode>". Pack ids have dots; modes do not.
function splitKey(string $key): array
{
    $i = strrpos($key, '.');
    return [substr($key, 0, $i), substr($key, $i + 1)];
}

function fpmFiles(string $key, array $pools): array
{
    global $H;
    [$pack, $mode] = splitKey($key);
    $mode = str_replace('+', ',', $mode);
    $p = packMeta($pack);
    $ini = file_get_contents("{$p['dir']}/php.ini") . phpSettings("$H/logs/php-error.log");
    if ($mode !== 'off') $ini .= "zend_extension = xdebug\nxdebug.mode = \"$mode\"\n";
    // A reload waits this long for running requests, so none get a 503.
    $conf = "[global]\nerror_log = \"$H/logs/fpm-$key.log\"\nprocess_control_timeout = 10s\n";
    foreach ($pools as $pool) {
        $log = $pool['site'] ? siteLogDir($pool['site']) . '/php-error.log' : "$H/logs/php-error.log";
        $conf .= "\n[{$pool['name']}]\nlisten = \"{$pool['sock']}\"\nlisten.mode = 0666\npm = ondemand\npm.max_children = 8\n"
            . "pm.process_idle_timeout = 60s\nclear_env = no\ncatch_workers_output = yes\ndecorate_workers_output = no\n"
            . "php_admin_value[error_log] = \"$log\"\n";
        if ($pool['site']) $conf .= "php_admin_value[xdebug.output_dir] = \"" . siteLogDir($pool['site']) . "/xdebug\"\n";
        if (isRoot()) {
            [$u, $g] = poolUser($pool['dir']);
            $conf .= "user = $u\ngroup = $g\n";
        }
    }
    return ["$H/conf/fpm/$key.conf" => $conf, "$H/conf/php/fpm-$key.ini" => $ini];
}

function waitSockets(array $socks, array $master, float $timeout): bool
{
    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline) {
        if (!alive($master)) return false;
        if (!array_filter($socks, fn($s) => @filetype($s) !== 'socket')) return true;
        usleep(20000);
    }
    return false;
}

// Makes one FPM master serve exactly $pools: starts it, reloads it, or stops
// it when no pool is left. A master with no pools exits on reload, so it is
// stopped instead.
function applyMaster(array &$state, string $key, array $pools): void
{
    global $H;
    $m = $state['fpm'][$key] ?? null;
    if (!$pools) {
        stopProc($m, SIGQUIT, 12);
        unset($state['fpm'][$key]);
        return;
    }
    $files = fpmFiles($key, $pools);
    $spec = sha1(implode("\0", $files));
    if (alive($m) && $m['spec'] === $spec) return;
    foreach ($files as $f => $c) writeIfChanged($f, $c);
    foreach ($pools as $pool) {
        if (!$pool['site']) continue;
        @mkdir(siteLogDir($pool['site']) . '/xdebug', 0777, true);
        if (isRoot()) {
            [$u, $g] = poolUser($pool['dir']);
            @touch(siteLogDir($pool['site']) . '/php-error.log');
            run(['chown', '-R', "$u:$g", siteLogDir($pool['site'])], null, false);
        }
    }
    $socks = array_column($pools, 'sock');
    $log = "$H/logs/fpm-$key.log";
    if (alive($m)) {
        posix_kill($m['pid'], SIGUSR2);
        if (!waitSockets($socks, $m, 15)) {
            throw new LeopardError('fpm_start_failed', "PHP-FPM ($key) did not reload.", EXIT_ERROR, 'See the log below.', $log);
        }
    } else {
        // A crashed master can leave workers behind that still hold its socket.
        killGroup($m);
        foreach ($socks as $s) @unlink($s);
        if (isRoot()) {
            // Pools run as another user and must be able to write the shared log.
            @touch("$H/logs/php-error.log");
            @chmod("$H/logs/php-error.log", 0666);
            @chmod("$H/logs/xdebug", 0777);
        }
        $pack = packMeta(splitKey($key)[0]);
        $m = spawn(["{$pack['dir']}/bin/php-fpm", '-F', '-y', array_keys($files)[0], '-c', array_keys($files)[1]], $log);
        if (!waitSockets($socks, $m, 10)) {
            stopProc($m, SIGKILL, 1);
            throw new LeopardError('fpm_start_failed', "PHP-FPM ($key) did not start: " . tailOf($log, 3), EXIT_ERROR, 'See the log below.', $log);
        }
    }
    $m['spec'] = $spec;
    $state['fpm'][$key] = $m;
}

function siteConf(array $pool): string
{
    $n = $pool['site'];
    return "Alias \"/$n\" \"{$pool['dir']}\"\n<Directory \"{$pool['dir']}\">\n  Options FollowSymLinks\n  AllowOverride All\n"
        . "  Require all granted\n  CGIPassAuth On\n  <FilesMatch \"\\.php$\">\n"
        . "    SetHandler \"proxy:unix:{$pool['sock']}|fcgi://p{$pool['gen']}-" . strtolower($n) . "\"\n  </FilesMatch>\n</Directory>\n"
        . "<Location \"/$n\">\n  SetEnv LEOPARD_SITE $n\n</Location>\n";
}

function httpdConf(array $root, int $port): string
{
    global $H, $R;
    $docroot = $root['dir'];
    $user = '';
    if (isRoot()) {
        // Apache will not serve pages as root. Run its workers as the owner of
        // the document root, or as nobody if that is root.
        [$u, $g] = poolUser($docroot);
        for ($d = dirname($docroot); $d !== '/'; $d = dirname($d)) {
            if (!(fileperms($d) & 0001)) fwrite(STDERR, "Warning: Apache runs as uid $u and cannot enter $d. Pages will return 403.\n");
        }
        $user = "User #$u\nGroup #$g\n";
    }
    $mods = '';
    foreach (['mpm_event', 'authz_core', 'authz_host', 'access_compat', 'alias', 'dir', 'mime', 'env', 'setenvif', 'headers',
        'expires', 'filter', 'deflate', 'rewrite', 'proxy', 'proxy_fcgi'] as $m) {
        $mods .= "LoadModule {$m}_module modules/mod_$m.so\n";
    }
    return <<<CONF
ServerRoot "$R"
ServerName localhost
Listen $port
{$user}PidFile "$H/run/apache.pid"
DefaultRuntimeDir "$H/run"
Mutex "file:$H/run" default
ErrorLog "$H/logs/apache-error.log"
# Epoch milliseconds, the site, then the request. `leopard logs` parses it.
LogFormat "%{msec}t %{LEOPARD_SITE}e %h \"%r\" %>s %b %D" leopard
CustomLog "$H/logs/apache-access.log" leopard
$mods
StartServers 1
ServerLimit 4
ThreadsPerChild 16
MaxRequestWorkers 64
MinSpareThreads 8
MaxSpareThreads 32
TypesConfig conf/mime.types
DocumentRoot "$docroot"
DirectoryIndex index.php index.html
# .htaccess and .htpasswd can hold secrets and rewrite rules. Never serve them.
<Files ".ht*">
  Require all denied
</Files>
<Directory "$docroot">
  Options FollowSymLinks
  AllowOverride All
  Require all granted
  CGIPassAuth On
  <FilesMatch "\.php$">
    SetHandler "proxy:unix:{$root['sock']}|fcgi://p{$root['gen']}-root"
  </FilesMatch>
</Directory>
# One file per site: an Alias /<name> to its folder and its own PHP.
IncludeOptional "$H/conf/sites/*.conf"

CONF;
}

// Writes Apache's config and makes Apache serve it: starts it, reloads it
// gracefully, or restarts it when the port changed. A graceful reload
// cannot move Apache to a new port.
function applyWeb(array &$state, array $pools, int $port): void
{
    global $H, $R;
    $files = ["$H/conf/httpd.conf" => httpdConf($pools['_root'], $port)];
    foreach ($pools as $p) if ($p['site']) $files["$H/conf/sites/{$p['site']}.conf"] = siteConf($p);
    foreach (glob("$H/conf/sites/*.conf") ?: [] as $f) if (!isset($files[$f])) @unlink($f);
    foreach ($files as $f => $c) writeIfChanged($f, $c);
    $spec = sha1(implode("\0", $files));
    $w = $state['web'] ?? null;
    if (alive($w) && !empty($w['legacy'])) {
        // Leopard 0.1 ran PHP inside Apache with another MPM. Start it fresh.
        stopProc($w, SIGTERM, 10);
        $w = null;
    }
    if (alive($w) && $w['port'] !== $port) {
        if (!portFree('0.0.0.0', $port)) {
            throw new LeopardError('web_start_failed', "Port $port is in use. Apache keeps running on its old port.", EXIT_ERROR,
                "Pick a free port with LEOPARD_PORT, or stop what listens on $port.");
        }
        stopProc($w, SIGTERM, 10);
    }
    if (alive($w)) {
        if ($w['spec'] === $spec) return;
        [$code, , $err] = run(["$R/bin/apache2", '-t', '-f', "$H/conf/httpd.conf"], null, false);
        if ($code !== 0) throw new LeopardError('web_config_invalid', "Apache rejected its config: $err", EXIT_ERROR, 'Apache keeps running with the old config.');
        posix_kill($w['pid'], SIGUSR1);
        usleep(100000);
        if (!alive($w)) throw new LeopardError('web_start_failed', 'Apache stopped after a reload.', EXIT_ERROR, 'See the log below.', "$H/logs/apache-error.log");
    } else {
        // Workers of a crashed Apache keep its port until they are gone.
        killGroup($state['web'] ?? null);
        @unlink("$H/run/apache.pid");
        // A killed worker with threads lets go of the port a moment after it
        // looks dead.
        // The same holds right after a stop.
        for ($i = 0; $i < 20 && !portFree('0.0.0.0', $port); $i++) usleep(100000);
        if (!portFree('0.0.0.0', $port)) {
            throw new LeopardError('web_start_failed', "Port $port is in use.", EXIT_ERROR, "Pick a free port with LEOPARD_PORT, or stop what listens on $port.");
        }
        $w = spawn(["$R/bin/apache2", '-f', "$H/conf/httpd.conf", '-D', 'FOREGROUND'], "$H/logs/apache-error.log");
        $deadline = microtime(true) + 10;
        while (!portAnswers($port)) {
            if (!alive($w) || microtime(true) > $deadline) {
                stopProc($w, SIGKILL, 1);
                throw new LeopardError('web_start_failed', "Apache did not start. Is port $port free? " . tailOf("$H/logs/apache-error.log", 2),
                    EXIT_ERROR, 'See the log below.', "$H/logs/apache-error.log");
            }
            usleep(20000);
        }
    }
    $state['web'] = $w + ['port' => $port];
    $state['web']['port'] = $port;
    $state['web']['spec'] = $spec;
}

// Makes the web server and the PHP masters match the wanted state. A site
// that moves to another master gets its new pool first, then Apache moves to
// the new socket, and only then does the old pool go away.
function applyPhp(array &$state, int $port, string $docroot): void
{
    $pools = wantedPools($state, $docroot);
    $final = [];
    foreach ($pools as $p) $final[$p['key']][$p['name']] = $p;
    // Before the switch, each master keeps its old pools as well.
    $before = $final;
    foreach ($state['pools'] as $name => $old) {
        if (($pools[$name]['sock'] ?? null) !== $old['sock'] && isset($state['fpm'][$old['key']])) {
            $before[$old['key']][$name] = $old + ['name' => $name];
        }
    }
    try {
        foreach ($before as $key => $ps) applyMaster($state, $key, $ps);
        applyWeb($state, $pools, $port);
    } finally {
        saveState($state);
    }
    $state['pools'] = array_map(fn($p) => ['key' => $p['key'], 'gen' => $p['gen'], 'sock' => $p['sock'], 'dir' => $p['dir'], 'site' => $p['site']], $pools);
    if ($before !== $final) {
        // Requests that Apache already sent to an old socket finish there.
        usleep(200000);
        foreach (array_keys($state['fpm']) as $key) applyMaster($state, $key, $final[$key] ?? []);
        foreach ($before as $ps) foreach ($ps as $p) if (($pools[$p['name']]['sock'] ?? null) !== $p['sock']) @unlink($p['sock']);
    }
    saveState($state);
}

// ---------------------------------------------------------------- database

function dbDataDir(string $engine): string
{
    global $H;
    // The MariaDB data folder keeps the path Leopard 0.1 used.
    return $engine === 'mariadb' ? "$H/mysql" : "$H/db/$engine";
}

function dbCommand(string $engine, int $port): array
{
    global $H, $R, $sock;
    $data = dbDataDir($engine);
    $common = ["--datadir=$data", "--socket=$sock", "--pid-file=$H/run/db.pid", "--port=$port", '--bind-address=127.0.0.1',
        "--log-error=$H/logs/db.log", '--skip-log-bin'];
    if ($engine === 'mariadb') {
        $cmd = array_merge(["$R/bin/mariadbd", '--no-defaults', "--basedir=$R"], $common, ["--plugin-dir=$R/mariadb/plugin",
            "--lc-messages-dir=$R/mariadb/share", "--character-sets-dir=$R/mariadb/share/charsets", '--innodb-buffer-pool-size=128M']);
    } else {
        $p = needDbPack($engine);
        @mkdir("$H/db/tmp", 0777, true);
        // Dev settings: MySQL's defaults use about twice the memory.
        $cmd = array_merge(["{$p['dir']}/bin/mysqld", '--no-defaults', "--basedir={$p['dir']}"], $common, ["--plugin-dir={$p['dir']}/plugin", "--lc-messages-dir={$p['dir']}/share",
            "--secure-file-priv=$H/db/tmp", '--performance-schema=OFF', '--innodb-buffer-pool-size=64M', '--innodb-redo-log-capacity=8M',
            '--mysqlx=OFF', '--mysql-native-password=ON']);
    }
    if (isRoot()) $cmd[] = '--user=root';
    return $cmd;
}

function dbConnect(string $user = 'root', string $pass = ''): mysqli
{
    global $sock;
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    return new mysqli('localhost', $user, $pass, '', 0, $sock);
}

function db(): mysqli
{
    try {
        return dbConnect();
    } catch (mysqli_sql_exception $e) {
        throw new LeopardError('not_running', 'The database is not running: ' . $e->getMessage(), EXIT_NOT_RUNNING, 'Run `leopard start` first.');
    }
}

function dbExists(mysqli $db, string $name): bool
{
    $q = $db->prepare('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $q->bind_param('s', $name);
    $q->execute();
    return (bool) $q->get_result()->fetch_row();
}

// A data folder for an engine, ready to start: MariaDB copies the seed that
// ships in the core; MySQL initializes one on first use.
function dbInit(string $engine): bool
{
    global $H, $R;
    $data = dbDataDir($engine);
    if (is_dir($data)) return false;
    @mkdir(dirname($data), 0777, true);
    $tmp = "$data.tmp" . getmypid();
    run(['rm', '-rf', $tmp]);
    if ($engine === 'mariadb') {
        run(['cp', '-R', "$R/seed/mysql", $tmp]);
    } else {
        $p = needDbPack($engine);
        $cmd = ["{$p['dir']}/bin/mysqld", '--no-defaults', "--basedir={$p['dir']}", "--lc-messages-dir={$p['dir']}/share", "--datadir=$tmp", '--initialize-insecure',
            '--innodb-redo-log-capacity=8M', "--log-error=$H/logs/db.log"];
        if (isRoot()) $cmd[] = '--user=root';
        [$code] = run($cmd, null, false);
        if ($code !== 0) throw new LeopardError('db_start_failed', "MySQL could not create its data folder.", EXIT_ERROR, 'See the log below.', "$H/logs/db.log");
    }
    rename($tmp, $data);
    return true;
}

// Starts the database for the configured engine, or keeps the running one.
// A running server keeps its port unless the port changed.
function applyDb(array &$state, string $engine, int $port): void
{
    global $H;
    $d = $state['db'] ?? null;
    if (alive($d) && ($d['engine'] ?? 'mariadb') === $engine && ($d['port'] ?? $port) === $port) {
        $state['db']['port'] = $port;
        return;
    }
    if (alive($d)) {
        if (($d['port'] ?? $port) !== $port && !portFree('127.0.0.1', $port)) {
            throw new LeopardError('db_start_failed', "Port $port is in use. The database keeps running on its old port.", EXIT_ERROR,
                "Pick a free port with LEOPARD_DB_PORT, or stop what listens on $port.");
        }
        stopProc($d, SIGTERM, 60);
    }
    if (!alive($d)) killGroup($d);
    $fresh = dbInit($engine);
    $d = spawn(dbCommand($engine, $port), "$H/logs/db.log");
    $deadline = microtime(true) + 60;
    while (true) {
        try {
            $db = dbConnect();
            break;
        } catch (mysqli_sql_exception) {
            if (!alive($d) || microtime(true) > $deadline) {
                stopProc($d, SIGKILL, 1);
                $state['db'] = null;
                throw new LeopardError('db_start_failed', "The database did not start. Is port $port free? " . tailOf("$H/logs/db.log", 2),
                    EXIT_ERROR, 'See the log below.', "$H/logs/db.log");
            }
            usleep(100000);
        }
    }
    if ($fresh && $engine !== 'mariadb') {
        // Same logins as the MariaDB seed: dev / dev, and root with no password.
        // Native passwords, because the bundled clients are MariaDB's.
        foreach (["ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY ''", "CREATE DATABASE IF NOT EXISTS wordpress",
            "CREATE USER IF NOT EXISTS 'dev'@'%' IDENTIFIED WITH mysql_native_password BY 'dev'",
            "CREATE USER IF NOT EXISTS 'dev'@'localhost' IDENTIFIED WITH mysql_native_password BY 'dev'", "GRANT ALL ON *.* TO 'dev'@'%' WITH GRANT OPTION",
            "GRANT ALL ON *.* TO 'dev'@'localhost' WITH GRANT OPTION"] as $q) $db->query($q);
    }
    $db->close();
    $state['db'] = $d + ['port' => $port, 'engine' => $engine];
}

function needDbPack(string $engine): array
{
    [$kind, $line] = explode('-', $engine, 2) + [1 => ''];
    foreach (installedPacks($kind) as $p) if ($p['line'] === $line || $p['version'] === $line) return $p;
    $entry = indexEntry($kind, $line) ?? throw new LeopardError('pack_missing', "No pack for $engine in this Leopard release.", EXIT_NOT_FOUND,
        'Run `leopard db list --available`.');
    return installPack($entry);
}

// ---------------------------------------------------------------- commands

function startStack(string $docroot): array
{
    global $H;
    $c = cfg();
    $lock = stateLock();
    $state = loadState();
    try {
        applyDb($state, $c['db'], $c['db_port']);
    } finally {
        saveState($state);
    }
    applyPhp($state, $c['port'], $docroot);
    return $state;
}

// Brings a running stack in line after a site or setting changed. Does
// nothing when Apache is not running; the next start applies it.
function applyChanges(): bool
{
    $lock = stateLock();
    $state = loadState();
    if (!alive($state['web'] ?? null)) return false;
    applyPhp($state, $state['web']['port'], docroot());
    return true;
}

function stopStack(): void
{
    global $H;
    $lock = stateLock();
    $state = loadState();
    stopProc($state['web'] ?? null, SIGTERM, 10);
    foreach ($state['fpm'] as $m) stopProc($m, SIGQUIT, 12);
    stopProc($state['db'] ?? null, SIGTERM, 60);
    foreach ($state['pools'] as $p) @unlink($p['sock']);
    @unlink("$H/run/apache.pid");
    saveState(['web' => null, 'db' => null, 'fpm' => [], 'pools' => []]);
}

// The folder served at /. Without one given, Leopard serves its own www
// folder, never the current one, so `leopard start` run in $HOME does not
// publish $HOME. An empty www gets a start page that lists the sites.
function docroot(): string
{
    global $H;
    $dir = cfg()['docroot'] ?? "$H/www";
    if ($dir === "$H/www") {
        @mkdir($dir, 0777, true);
        if (count(scandir($dir)) === 2) file_put_contents("$dir/index.php", startPage());
    }
    return $dir;
}

function startPage(): string
{
    $reg = var_export(registryDir(), true);
    return <<<PHP
<?php
\$sites = [];
foreach (glob($reg . '/*.json') ?: [] as \$f) {
    \$s = json_decode(file_get_contents(\$f), true);
    if ((\$s['state'] ?? '') === 'ready') \$sites[] = htmlspecialchars(\$s['name']);
}
?><!doctype html>
<meta charset="utf-8"><meta name="viewport" content="width=device-width">
<title>Leopard</title>
<style>
body { font: 17px/1.6 system-ui, sans-serif; max-width: 36rem; margin: 4rem auto; padding: 0 1rem; color: #1c1917; }
h1 { font-size: 1.8rem; margin-bottom: .2rem; } p.sub { color: #78716c; margin-top: 0; }
a { color: #b45309; } code { background: #f5f5f4; padding: .1em .35em; border-radius: 4px; }
ul { padding: 0; list-style: none; } li { padding: .5rem 0; border-bottom: 1px solid #e7e5e4; }
li a.admin { float: right; font-size: .9em; }
.dot { display: inline-block; width: .55em; height: .55em; border-radius: 50%; background: #16a34a; margin-right: .45em; vertical-align: .15em; }
</style>
<h1><span class="dot"></span>Leopard is running</h1>
<p class="sub">Apache, PHP, and MariaDB in one file.</p>
<?php if (\$sites): ?>
<ul><?php foreach (\$sites as \$n): ?><li><a href="/<?= \$n ?>/"><?= \$n ?></a><a class="admin" href="/<?= \$n ?>/wp-admin/">admin</a></li><?php endforeach ?></ul>
<?php else: ?>
<p>No sites yet. Make one:</p>
<p><code>leopard site create mysite</code></p>
<?php endif ?>
<p>Log in as <code>admin</code> / <code>password</code>. To serve your own folder here: <code>leopard start /path/to/folder</code></p>

PHP;
}

function cmdStart(array $args, bool $restart = false): void
{
    global $H, $R;
    $c = cfg();
    $dir = $args[0] ?? docroot();
    if (!is_dir($dir)) throw new LeopardError('not_found', "Document root does not exist: $dir", EXIT_NOT_FOUND);
    $docroot = realpath($dir);
    if ($restart) stopStack();
    startStack($docroot);
    // Saved only once every server runs, so a bad port is never kept for the
    // next start (or for systemd at boot).
    $s = savedSettings();
    $s['port'] = $c['port'];
    $s['db_port'] = $c['db_port'];
    $s['url'] = $c['url_set'];
    $s['docroot'] = $docroot;
    $s['bin'] = $c['bin'];
    saveSettings($s);
    // After an upgrade, links from `leopard link` still point at the old runtime.
    if (is_link("$H/bin/php") && readlink("$H/bin/php") !== "$R/bin/php") run(["$R/leopard", 'link'], null, false);
    removeOldRuntimes();
    status();
}

// Each new Leopard file unpacks a new runtime next to the old ones. After a
// good start, delete the old ones that no process still runs.
function removeOldRuntimes(): void
{
    global $H, $R;
    $old = array_filter(glob("$H/runtime/*", GLOB_ONLYDIR) ?: [], fn($d) => preg_match('/^[0-9a-f]{12}$/', basename($d)) && basename($d) !== basename($R));
    if (!$old) return;
    $lock = fopen("$H/runtime/.lock", 'ce');
    // Skip this time if a Leopard file is unpacking a runtime right now.
    if (!flock($lock, LOCK_EX | LOCK_NB)) return;
    $used = '';
    foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $f) $used .= str_replace("\0", "\n", (string) @file_get_contents($f)) . "\n";
    foreach (glob('/proc/[0-9]*/exe') ?: [] as $f) $used .= @readlink($f) . "\n";
    foreach ($old as $d) {
        if (!str_contains($used, (realpath($d) ?: $d) . '/')) run(['rm', '-rf', '--', $d], null, false);
    }
}

function versions(): array
{
    global $R;
    $v = [];
    foreach (file("$R/VERSIONS", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        [$name, $version] = explode(' ', $line, 2);
        $v[$name] = $version;
    }
    $v['php'] = packMeta(defaultPhp())['version'];
    return ['leopard' => packIndex()['release'] ?? 'dev'] + $v;
}

function status(): void
{
    global $H, $R, $sock;
    $c = cfg();
    $st = loadState();
    $web = alive($st['web'] ?? null) ? $st['web']['pid'] : null;
    $dbPid = alive($st['db'] ?? null) ? $st['db']['pid'] : null;
    $engine = $st['db']['engine'] ?? $c['db'];
    $php = packMeta(defaultPhp());
    $sites = array_map(function ($s) {
        $v = siteView($s);
        return ['name' => $v['name'], 'url' => $v['url'], 'dir' => $v['dir'], 'php' => $v['runs']['php'], 'xdebug' => $v['runs']['xdebug']];
    }, allSites());
    $fpm = [];
    foreach ($st['fpm'] as $key => $m) {
        [$pack, $mode] = splitKey($key);
        $fpm[] = ['key' => $key, 'php' => packMeta($pack)['version'] ?? null, 'xdebug' => str_replace('+', ',', $mode),
            'running' => alive($m), 'pid' => $m['pid'],
            'pools' => array_keys(array_filter($st['pools'], fn($p) => $p['key'] === $key))];
    }
    $data = [
        'home' => $H,
        'runtime' => $R,
        'versions' => versions(),
        'web' => ['running' => (bool) $web, 'pid' => $web, 'server' => 'apache', 'port' => $st['web']['port'] ?? $c['port'], 'url' => $c['url'], 'docroot' => $c['docroot']],
        'db' => ['running' => (bool) $dbPid, 'pid' => $dbPid, 'engine' => $engine, 'host' => '127.0.0.1', 'port' => $st['db']['port'] ?? $c['db_port'], 'socket' => $sock,
            'user' => DB_USER, 'password' => DB_PASSWORD, 'root_user' => 'root'],
        'php' => ['default' => $php['version'], 'default_pack' => $php['id']],
        'fpm' => $fpm,
        'xdebug_mode' => $c['xdebug'],
        'sites' => $sites,
    ];
    $text = sprintf("apache: %s\n%s: %s\n", $web ? "running (pid $web)" : 'stopped', $engine, $dbPid ? "running (pid $dbPid)" : 'stopped');
    foreach ($fpm as $m) $text .= "php-fpm {$m['php']} xdebug={$m['xdebug']}: " . ($m['running'] ? "running (pid {$m['pid']})" : 'stopped') . ' ' . implode(', ', $m['pools']) . "\n";
    if ($c['docroot']) $text .= "docroot: {$c['docroot']}\n";
    $text .= "web: {$c['url']}\n";
    $text .= sprintf("db: 127.0.0.1:%d or %s (user %s, password %s; database wordpress)\n", $data['db']['port'], $sock, DB_USER, DB_PASSWORD);
    $text .= "php: {$php['version']} (default)\nxdebug: {$c['xdebug']} (default)\n";
    foreach ($sites as $s) $text .= "site {$s['name']}: {$s['url']}/  php {$s['php']}, xdebug {$s['xdebug']}\n";
    out($data, $text);
}

function config(array $args): void
{
    $c = cfg();
    if ($args) {
        if (($args[0] ?? '') !== 'set' || count($args) !== 3) throw new LeopardError('usage', 'Usage: leopard config [set port|db_port|url VALUE]', EXIT_USAGE);
        [, $k, $v] = $args;
        switch ($k) {
            case 'port':
            case 'db_port':
                if (!ctype_digit($v) || (int) $v < 1 || (int) $v > 65535) throw new LeopardError('bad_value', "Not a port: $v", EXIT_USAGE);
                $v = (int) $v;
                break;
            case 'url':
                if (!preg_match('#^https?://[^\s]+$#', $v)) throw new LeopardError('bad_value', 'The url must start with http:// or https:// and have no spaces.', EXIT_USAGE);
                $v = rtrim($v, '/');
                break;
            default:
                throw new LeopardError('usage', "Unknown setting: $k. Settings: port, db_port, url", EXIT_USAGE);
        }
        $s = savedSettings();
        $changed = ($s[$k] ?? null) !== $v;
        $s[$k] = $v;
        saveSettings($s);
        fwrite(STDERR, "Saved $k=$v. Run `leopard restart` to apply it.\n");
        $saved = savedSettings();
        $c['port'] = $saved['port'] ?? 8080;
        $c['db_port'] = $saved['db_port'] ?? 3306;
        $c['url'] = $saved['url'] ?? "http://localhost:{$c['port']}";
        $c['changed'] = $changed;
    }
    $data = ['port' => $c['port'], 'db_port' => $c['db_port'], 'url' => $c['url'], 'docroot' => $c['docroot'], 'xdebug' => $c['xdebug'],
        'php' => packMeta(defaultPhp())['version'], 'db' => $c['db']] + (isset($c['changed']) ? ['changed' => $c['changed']] : []);
    $text = '';
    foreach ($data as $k => $v) if ($k !== 'changed') $text .= "$k=$v\n";
    out($data, $args ? '' : $text);
}

function xdebugCmd(array $args): void
{
    if ($args) {
        $mode = normMode($args[0]);
        $s = savedSettings();
        $changed = ($s['xdebug'] ?? 'off') !== $mode;
        $s['xdebug'] = $mode;
        saveSettings($s);
        prepare();
        if ($changed) applyChanges();
    }
    $mode = cfg()['xdebug'];
    out(['xdebug_mode' => $mode] + (isset($changed) ? ['changed' => $changed] : []),
        "Xdebug mode: $mode (the default for sites without their own). Per site: leopard site set NAME --xdebug MODE. For one command only, set XDEBUG_MODE.\n");
}

function doctor(): void
{
    global $H;
    $c = cfg();
    $st = loadState();
    $checks = [];
    $add = function (string $name, bool $ok, string $detail = '') use (&$checks) {
        $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    };
    $web = alive($st['web'] ?? null);
    $dbUp = alive($st['db'] ?? null);
    $add('apache running', $web, $web ? "pid {$st['web']['pid']}" : 'run `leopard start`');
    $add('database running', $dbUp, $dbUp ? ($st['db']['engine'] ?? 'mariadb') . " pid {$st['db']['pid']}" : 'run `leopard start`');
    foreach ($st['fpm'] as $key => $m) {
        $add("php-fpm $key running", alive($m), alive($m) ? "pid {$m['pid']}" : 'run `leopard start` to restart it');
    }
    if ($web) {
        $code = httpGet("{$c['url']}/");
        $add("web answers at {$c['url']}/", $code !== null, $code ? "HTTP $code" : 'no answer; check the url setting and the port');
    }
    $db = null;
    if ($dbUp) {
        $port = $st['db']['port'] ?? $c['db_port'];
        try {
            $tcp = new mysqli('127.0.0.1', DB_USER, DB_PASSWORD, '', $port);
            $add('db login over TCP as dev', true, "127.0.0.1:$port");
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
        // PHP runs in PHP-FPM, not in Apache, so Apache rejects these lines.
        $ht = @file_get_contents("{$s['dir']}/.htaccess");
        if ($ht !== false && preg_match('/^\s*php_(value|flag|admin_value|admin_flag)\b/mi', $ht)) {
            $add("site $n: .htaccess", false, 'it sets php_value or php_flag, which only work with mod_php; move those settings to .user.ini');
        }
        if ($web) {
            $code = httpGet("{$s['url']}/wp-login.php");
            $add("site $n: wp-login.php", $code === 200, $code ? "HTTP $code" : 'no answer');
        }
    }
    $failed = array_filter($checks, fn($x) => !$x['ok']);
    global $json;
    if ($json) {
        echo json_encode(['ok' => !$failed, 'api' => API, 'checks' => $checks], JSON_FLAGS), "\n";
    } else {
        foreach ($checks as $x) echo ($x['ok'] ? 'ok    ' : 'FAIL  ') . $x['name'] . ($x['detail'] ? "  ({$x['detail']})" : '') . "\n";
    }
    exit($failed ? EXIT_ERROR : 0);
}

function httpGet(string $url): ?int
{
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 3]);
    curl_exec($c);
    $code = curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    return $code ?: null;
}

// Parses `--flag value` and repeatable flags. Flags in $multi collect arrays.
// Flags in $bool take no value.
function parseFlags(array $args, array $known, array $multi = [], array $bool = []): array
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
        if (in_array($key, $bool, true)) {
            $flags[$key] = true;
            continue;
        }
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

function wp(array $site, array $args, ?string $stdin = null, bool $check = true): array
{
    global $R;
    $php = sitePhp($site);
    return run(array_merge(["$R/bin/wp", "--path={$site['dir']}"], $args), $stdin, $check, ['LEOPARD_PHP' => "{$php['dir']}/bin/php"]);
}

function siteCreate(array $args): void
{
    global $H, $sock;
    [$pos, $f] = parseFlags($args, ['dir', 'title', 'wp-version', 'import-sql', 'content', 'from-url', 'admin-user', 'admin-password', 'admin-email', 'php', 'xdebug'], ['plugin', 'activate']);
    if (count($pos) !== 1) throw new LeopardError('usage', 'Usage: leopard site create NAME [options]. See `leopard help`.', EXIT_USAGE);
    $name = $pos[0];
    validName($name);
    $dir = $f['dir'] ?? "$H/sites/$name";
    if (!str_starts_with($dir, '/')) $dir = getcwd() . "/$dir";
    $dir = rtrim($dir, '/');
    if ($dir === '') throw new LeopardError('usage', '--dir cannot be /', EXIT_USAGE);
    $url = cfg()['url'] . "/$name";
    $adminUser = $f['admin-user'] ?? 'admin';
    $adminPassword = $f['admin-password'] ?? 'password';
    if (isset($f['content']) && !isset($f['import-sql'])) throw new LeopardError('usage', '--content needs --import-sql', EXIT_USAGE);
    foreach (['import-sql', 'content'] as $k) {
        if (isset($f[$k]) && !is_file($f[$k])) throw new LeopardError('not_found', "--$k file not found: {$f[$k]}", EXIT_NOT_FOUND);
    }
    $xdebug = isset($f['xdebug']) ? normMode($f['xdebug']) : null;
    $plugins = [];
    foreach ($f['plugin'] as $spec) {
        [$pname, $path] = str_contains($spec, '=') ? explode('=', $spec, 2) : [null, $spec];
        $target = realpath($path);
        if ($target === false || !is_dir($target)) throw new LeopardError('not_found', "Plugin folder not found: $path", EXIT_NOT_FOUND);
        $plugins[] = ['name' => $pname ?? basename($target), 'target' => $target];
    }
    $php = isset($f['php']) ? needPhp($f['php'])['id'] : null;

    $lock = sitesLock();
    if ($old = readSite($name)) {
        throw ($old['state'] ?? 'ready') === 'creating'
            ? new LeopardError('exists', "An earlier create of '$name' did not finish.", EXIT_EXISTS, "Run `leopard site delete $name` to remove what it left, then create it again.")
            : new LeopardError('exists', "Site '$name' already exists.", EXIT_EXISTS, "Use `leopard site info $name`, or `leopard site delete $name` first.");
    }
    if (file_exists($dir) || is_link($dir)) throw new LeopardError('exists', "Folder already exists: $dir", EXIT_EXISTS, 'Leopard never adopts an existing folder. Pick another --dir.');
    $db = db();
    if (dbExists($db, $name)) throw new LeopardError('exists', "Database already exists: $name", EXIT_EXISTS, 'Leopard never adopts an existing database.');
    if (!alive(loadState()['web'] ?? null)) throw new LeopardError('not_running', 'Apache is not running.', EXIT_NOT_RUNNING, 'Run `leopard start` first.');

    // The record comes first, marked "creating". If this run is killed
    // outright, `site delete` can still find and remove what it made.
    $record = registryDir() . "/$name.json";
    $site = ['name' => $name, 'dir' => $dir, 'url' => $url, 'db' => $name, 'php' => $php, 'xdebug' => $xdebug, 'state' => 'creating', 'plugins' => []];
    writeAtomic($record, json_encode($site, JSON_FLAGS) . "\n");
    // Undo, newest first, everything this run made if a later step fails.
    $undo = [function () use ($record) { @unlink($record); try { applyChanges(); } catch (Throwable) {} }];
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
        $download = fn($v) => wp($site, array_merge(['core', 'download', '--quiet'], $v ? ["--version=$v"] : []), null, false);
        [$code, , $err] = $download($wpVersion);
        if ($code !== 0 && $wpVersion === null && ($cached = newestCachedWordPress())) {
            // wordpress.org is unreachable; WP-CLI's cache still has a copy.
            [$code, , $err] = $download($cached);
        }
        if ($code !== 0) throw new LeopardError('download_failed', "Could not download WordPress: $err", EXIT_ERROR, 'Check network access to wordpress.org, or pass --wp-version for a version in the WP-CLI cache.');

        $db->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $undo[] = fn() => $db->query("DROP DATABASE IF EXISTS `$name`");
        wp($site, ['config', 'create', "--dbname=$name", '--dbuser=' . DB_USER, '--dbpass=' . DB_PASSWORD,
            // The socket path never changes for a home; the TCP port can.
            "--dbhost=localhost:$sock", '--dbcharset=utf8mb4', '--extra-php'],
            "define( 'WP_ENVIRONMENT_TYPE', 'development' );\n");

        // The site's own PHP pool and its Alias in Apache.
        applyChanges();

        if (isset($f['import-sql'])) {
            if (isset($f['content'])) {
                $zip = new ZipArchive();
                if ($zip->open($f['content']) !== true || !$zip->extractTo($dir)) throw new LeopardError('import_failed', "Could not unpack {$f['content']}");
                $zip->close();
            }
            wp($site, ['db', 'import', realpath($f['import-sql'])]);
            $from = rtrim($f['from-url'] ?? wp($site, ['option', 'get', 'home', '--skip-plugins', '--skip-themes'])[1], '/');
            if ($from !== $url) wp($site, ['search-replace', $from, $url, '--all-tables', '--precise', '--skip-plugins', '--skip-themes', '--quiet']);
            if (wp($site, ['user', 'get', $adminUser, '--field=ID', '--skip-plugins', '--skip-themes'], null, false)[0] === 0) {
                wp($site, ['user', 'update', $adminUser, "--user_pass=$adminPassword", '--skip-email', '--skip-plugins', '--skip-themes']);
            } else {
                wp($site, ['user', 'create', $adminUser, $f['admin-email'] ?? 'admin@example.test', '--role=administrator', "--user_pass=$adminPassword", '--skip-plugins', '--skip-themes']);
            }
            $source = 'import';
        } else {
            wp($site, ['core', 'install', "--url=$url", '--title=' . ($f['title'] ?? $name), "--admin_user=$adminUser",
                "--admin_password=$adminPassword", '--admin_email=' . ($f['admin-email'] ?? 'admin@example.test'), '--skip-email']);
            wp($site, ['rewrite', 'structure', '/%postname%/']);
            $source = 'fresh';
        }
        if (isset($f['title']) && $source === 'import') wp($site, ['option', 'update', 'blogname', $f['title'], '--skip-plugins', '--skip-themes']);

        // Pretty permalinks under /<name>/. Written here, so it never depends
        // on WP-CLI detecting mod_rewrite.
        file_put_contents("$dir/.htaccess", "# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteBase /$name/\nRewriteRule ^index\\.php$ - [L]\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule . /$name/index.php [L]\n</IfModule>\n# END WordPress\n");

        foreach ($plugins as $p) {
            $link = "$dir/wp-content/plugins/{$p['name']}";
            if (file_exists($link) || is_link($link)) throw new LeopardError('exists', "Plugin folder already exists in the site: {$p['name']}", EXIT_EXISTS);
            symlink($p['target'], $link);
        }
        if ($f['activate']) wp($site, array_merge(['plugin', 'activate'], $f['activate']));

        $version = preg_match("/\\\$wp_version = '([^']+)'/", file_get_contents("$dir/wp-includes/version.php"), $m) ? $m[1] : null;
        $site = [
            'name' => $name, 'dir' => $dir, 'url' => $url, 'db' => $name, 'php' => $php, 'xdebug' => $xdebug, 'source' => $source,
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
    out(['site' => $view], "Created site $name ($source, WordPress $version, PHP {$view['runs']['php']})\nurl: $url/\nadmin: $url/wp-admin/ (user $adminUser, password $adminPassword)\ndir: $dir\n");
}

function siteSet(array $args): void
{
    [$pos, $f] = parseFlags($args, ['php', 'xdebug']);
    if (count($pos) !== 1 || !$f) throw new LeopardError('usage', 'Usage: leopard site set NAME [--php VERSION|default] [--xdebug MODE|default]', EXIT_USAGE);
    $lock = sitesLock();
    $site = needSite($pos[0]);
    $before = $site;
    if (isset($f['php'])) $site['php'] = $f['php'] === 'default' ? null : needPhp($f['php'])['id'];
    if (isset($f['xdebug'])) $site['xdebug'] = $f['xdebug'] === 'default' ? null : normMode($f['xdebug']);
    $changed = $site !== $before;
    if ($changed) {
        writeAtomic(registryDir() . "/{$site['name']}.json", json_encode($site, JSON_FLAGS) . "\n");
        applyChanges();
    }
    $v = siteView($site);
    out(['site' => $v, 'changed' => $changed], "Site {$site['name']}: PHP {$v['runs']['php']}, Xdebug {$v['runs']['xdebug']}" . ($changed ? '' : ' (no change)') . "\n");
}

function siteDelete(array $args): void
{
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
    unlink(registryDir() . "/$name.json");
    applyChanges();
    run(['rm', '-rf', '--', siteLogDir($name)], null, false);
    out(['deleted' => $name, 'db' => $site['db'], 'dir' => $site['dir'], 'folder' => $folder],
        "Deleted site $name: database {$site['db']}; folder {$site['dir']} $folder\n");
}

// Runs WP-CLI or PHP for one site, with that site's PHP. Replaces this process,
// so the terminal, stdin, and exit code are the tool's own.
function siteExec(string $tool, array $args): never
{
    global $R;
    if (!$args) throw new LeopardError('usage', "Usage: leopard site $tool NAME ARGS...", EXIT_USAGE);
    $site = needSite(array_shift($args));
    $php = sitePhp($site)['dir'] . '/bin/php';
    $env = ['LEOPARD_PHP' => $php] + childEnv(true);
    if ($tool === 'wp') pcntl_exec("$R/bin/wp", array_merge(["--path={$site['dir']}"], $args), $env);
    else pcntl_exec($php, $args, $env);
    throw new LeopardError('exec_failed', "Could not run $tool.");
}

function phpCmd(array $args): void
{
    $sub = $args[0] ?? 'list';
    switch ($sub) {
        case 'list':
            $default = defaultPhp();
            $installed = array_map(fn($p) => ['version' => $p['version'], 'line' => $p['line'], 'pack' => $p['id'], 'default' => $p['id'] === $default], installedPacks('php'));
            $available = array_values(array_map(fn($p) => ['version' => $p['version'], 'line' => $p['line'], 'pack' => $p['id'], 'installed' => (bool) packMeta($p['id'])],
                array_filter(packIndex()['packs'], fn($p) => $p['kind'] === 'php')));
            $text = '';
            foreach ($installed as $p) $text .= "{$p['version']}" . ($p['default'] ? '  (default)' : '') . "\n";
            if (in_array('--available', $args, true)) foreach ($available as $p) if (!$p['installed']) $text .= "{$p['version']}  (available: leopard php install {$p['line']})\n";
            out(['installed' => $installed, 'available' => $available], $text);
            return;
        case 'install':
            if (count($args) !== 2) throw new LeopardError('usage', 'Usage: leopard php install VERSION', EXIT_USAGE);
            $had = (bool) findPhp($args[1]);
            $p = needPhp($args[1]);
            prepare();
            out(['php' => ['version' => $p['version'], 'pack' => $p['id']], 'changed' => !$had], "PHP {$p['version']} is installed.\n");
            return;
        case 'default':
            if (count($args) !== 2) throw new LeopardError('usage', 'Usage: leopard php default VERSION', EXIT_USAGE);
            $p = needPhp($args[1]);
            $s = savedSettings();
            $changed = defaultPhp() !== $p['id'];
            $s['php'] = $p['id'];
            saveSettings($s);
            prepare();
            if ($changed) applyChanges();
            out(['php' => ['version' => $p['version'], 'pack' => $p['id']], 'changed' => $changed],
                "PHP {$p['version']} is now the default: for `leopard php`, the docroot, and sites without their own version.\n");
            return;
    }
    throw new LeopardError('usage', "Unknown php command: $sub. Use list, install, or default.", EXIT_USAGE);
}

// Packs that something still uses: the PHP this Leopard file came with, the
// default, every site's PHP, every running server, and the PHP that other
// unpacked Leopard files came with.
function packsInUse(): array
{
    global $H;
    $use = [controlPhp() => 'this Leopard file', defaultPhp() => 'default PHP'];
    foreach (glob("$H/runtime/*/CONTROL_PHP") ?: [] as $f) $use[trim(file_get_contents($f))] ??= 'another Leopard file';
    foreach (allSites() as $s) if (!empty($s['php'])) $use[$s['php']] ??= "site {$s['name']}";
    $st = loadState();
    foreach ($st['fpm'] as $key => $m) if (alive($m)) $use[splitKey($key)[0]] ??= 'running';
    $engine = cfg()['db'];
    foreach (installedPacks('mysql') as $p) {
        if ($engine === "mysql-{$p['line']}" || $engine === "mysql-{$p['version']}") $use[$p['id']] ??= 'database';
    }
    return $use;
}

function packsCmd(array $args): void
{
    [$pos, $f] = parseFlags($args, ['sha256'], [], ['dry-run']);
    $sub = $pos[0] ?? 'list';
    switch ($sub) {
        case 'list':
            $use = packsInUse();
            $packs = [];
            foreach (array_merge(installedPacks('php'), installedPacks('mysql')) as $p) {
                $packs[] = ['pack' => $p['id'], 'kind' => $p['kind'], 'version' => $p['version'], 'used_by' => $use[$p['id']] ?? null];
            }
            out(['packs' => $packs], implode('', array_map(fn($p) => "{$p['pack']}" . ($p['used_by'] ? "  ({$p['used_by']})" : '') . "\n", $packs)));
            return;
        case 'add':
            if (count($pos) !== 2 || !is_file($pos[1])) throw new LeopardError('usage', 'Usage: leopard packs add FILE [--sha256 SHA]', EXIT_USAGE);
            $sha = hash_file('sha256', $pos[1]);
            $entry = null;
            foreach (packIndex()['packs'] as $p) if ($p['sha256'] === $sha) $entry = $p;
            if (!$entry) {
                if (($f['sha256'] ?? null) !== $sha) {
                    throw new LeopardError('verify_failed', 'This Leopard file does not know that pack.', EXIT_ERROR,
                        'Pass --sha256 with the checksum from the release that built it to install it anyway.');
                }
                $meta = json_decode(run(['tar', '-xzOf', $pos[1], './pack.json'])[1], true);
                $entry = ['id' => "{$meta['kind']}-{$meta['version']}-" . substr($sha, 0, 12), 'kind' => $meta['kind'], 'version' => $meta['version'], 'sha256' => $sha];
            }
            $had = (bool) packMeta($entry['id']);
            $p = installPack($entry, realpath($pos[1]));
            prepare();
            out(['pack' => $p['id'], 'changed' => !$had], "Installed {$p['id']}.\n");
            return;
        case 'gc':
            $use = packsInUse();
            $removed = [];
            $bytes = 0;
            foreach (array_merge(installedPacks('php'), installedPacks('mysql')) as $p) {
                if (isset($use[$p['id']])) continue;
                $bytes += (int) explode("\t", run(['du', '-sk', $p['dir']])[1])[0] * 1024;
                $removed[] = $p['id'];
                if (empty($f['dry-run'])) run(['rm', '-rf', '--', $p['dir']]);
            }
            out(['removed' => $removed, 'bytes' => $bytes, 'dry_run' => !empty($f['dry-run'])],
                ($removed ? (empty($f['dry-run']) ? 'Removed ' : 'Would remove ') . implode(', ', $removed) . sprintf(' (%.0f MB)', $bytes / 1e6) : 'Nothing to remove') . ".\n");
            return;
    }
    throw new LeopardError('usage', "Unknown packs command: $sub. Use list, add, or gc.", EXIT_USAGE);
}

// ---------------------------------------------------------------- logs

// Every log file for a kind: [kind, file, site].
function logSources(?string $site, array $kinds): array
{
    global $H;
    $src = [];
    foreach ($kinds as $k) {
        switch ($k) {
            case 'php':
                if ($site) { $src[] = ['php', siteLogDir($site) . '/php-error.log', $site]; break; }
                $src[] = ['php', "$H/logs/php-error.log", null];
                foreach (glob("$H/logs/sites/*/php-error.log") ?: [] as $f) $src[] = ['php', $f, basename(dirname($f))];
                break;
            case 'access': $src[] = ['access', "$H/logs/apache-access.log", null]; break;
            case 'web': $src[] = ['web', "$H/logs/apache-error.log", null]; break;
            case 'db': $src[] = ['db', "$H/logs/db.log", null]; $src[] = ['db', "$H/logs/mariadb.log", null]; break;
            case 'fpm': foreach (glob("$H/logs/fpm-*.log") ?: [] as $f) $src[] = ['fpm', $f, null]; break;
            default: throw new LeopardError('usage', "Unknown log kind: $k. Kinds: php, access, fpm, web, db.", EXIT_USAGE);
        }
    }
    return array_values(array_filter($src, fn($x) => is_file($x[1])));
}

// Parses one log line, or returns null for a line that continues the one
// before it (a PHP stack trace).
function parseLogLine(string $kind, string $line, ?string $site): ?array
{
    $e = ['ts' => null, 'site' => $site, 'kind' => $kind, 'level' => 'info', 'message' => $line];
    switch ($kind) {
        case 'access':
            if (!preg_match('/^(\d{10,})\s+(\S+)\s+(.*\s(\d{3})\s.*)$/', $line, $m)) return $e;
            $e['ts'] = (int) $m[1] / 1000;
            $e['site'] = $m[2] === '-' ? null : $m[2];
            $e['message'] = $m[3];
            $e['level'] = $m[4] >= 500 ? 'error' : ($m[4] >= 400 ? 'warning' : 'info');
            return $e;
        case 'php':
        case 'fpm':
            if (!preg_match('/^\[(\d\d-\w{3}-\d{4} \d\d:\d\d:\d\d)(?: \w+)?\]\s*(.*)$/', $line, $m)) return null;
            $e['ts'] = strtotime("$m[1] UTC");
            $e['message'] = $m[2];
            $l = strtolower($m[2]);
            $e['level'] = preg_match('/fatal|parse error|^error|uncaught/', $l) ? 'error' : (preg_match('/warning/', $l) ? 'warning'
                : (preg_match('/deprecated|notice/', $l) ? 'notice' : 'info'));
            return $e;
        case 'web':
            if (!preg_match('/^\[\w{3} (\w{3} \d\d \d\d:\d\d:\d\d)(?:\.\d+)? (\d{4})\] \[[^:\]]*:(\w+)\]\s*(.*)$/', $line, $m)) return null;
            $e['ts'] = strtotime("$m[1] $m[2] UTC");
            $e['level'] = in_array($m[3], ['emerg', 'alert', 'crit', 'error'], true) ? 'error' : ($m[3] === 'warn' ? 'warning' : 'info');
            $e['message'] = $m[4];
            return $e;
        case 'db':
            if (!preg_match('/^(\d{4}-\d\d-\d\d[ T]\d\d:\d\d:\d\d)\S*\s+\S+\s+\[(\w+)\]\s*(.*)$/', $line, $m)) return $e;
            $e['ts'] = strtotime("$m[1] UTC");
            $e['level'] = strtolower($m[2]) === 'note' || strtolower($m[2]) === 'system' ? 'info' : strtolower($m[2]);
            $e['message'] = $m[3];
            return $e;
    }
    return $e;
}

// Reads entries from each file, from its cursor offset (or near its end).
// Returns [entries, new cursor, reset]. A file that was rotated or emptied
// since the cursor is read from the start, and reset is true.
function readLogs(array $sources, ?array $cursor): array
{
    $entries = [];
    $next = [];
    $reset = false;
    foreach ($sources as [$kind, $file, $site]) {
        $st = @stat($file);
        if (!$st) continue;
        $from = max(0, $st['size'] - 4 * 1024 * 1024);
        if ($cursor !== null) {
            $c = $cursor[$file] ?? null;
            if ($c && $c[0] === $st['ino'] && $c[1] <= $st['size']) $from = $c[1];
            elseif ($c) { $from = 0; $reset = true; }
            else $from = 0;
        }
        $h = fopen($file, 'r');
        fseek($h, $from);
        $data = stream_get_contents($h, $st['size'] - $from);
        fclose($h);
        // A line still being written stays for the next read.
        $end = strrpos($data, "\n");
        $data = $end === false ? '' : substr($data, 0, $end + 1);
        $next[$file] = [$st['ino'], $from + strlen($data)];
        $last = null;
        foreach (explode("\n", rtrim($data, "\n")) as $line) {
            if ($line === '') continue;
            $e = parseLogLine($kind, $line, $site);
            if ($e === null) {
                if ($last !== null) $entries[$last]['message'] .= "\n" . $line;
                continue;
            }
            $entries[] = $e + ['file' => $file];
            $last = array_key_last($entries);
        }
    }
    usort($entries, fn($a, $b) => ($a['ts'] ?? 0) <=> ($b['ts'] ?? 0));
    return [$entries, $next, $reset];
}

function encodeCursor(array $c): string { return rtrim(strtr(base64_encode(json_encode($c)), '+/', '-_'), '='); }

function decodeCursor(string $s): ?array
{
    $c = json_decode((string) base64_decode(strtr($s, '-_', '+/')), true);
    return is_array($c) ? $c : null;
}

function logsCmd(array $args): void
{
    global $json;
    // Kept from Leopard 0.1: `leopard logs apache|access|php|mariadb`.
    $alias = ['apache' => 'web', 'mariadb' => 'db'];
    $kinds = [];
    $rest = [];
    for ($i = 0; $i < count($args); $i++) {
        $a = $args[$i];
        if ($a === '-n') { $args[$i] = '--n'; $rest[] = '--n'; continue; }
        if ($a === '-f' || $a === '--follow') { $rest[] = '--follow'; continue; }
        if (!str_starts_with($a, '--') && in_array($a, ['web', 'apache', 'access', 'php', 'mariadb', 'db', 'fpm', 'mark'], true) && ($i === 0 || !in_array($args[$i - 1], ['-n', '--n', '--site', '--kind', '--since', '--wait'], true))) {
            if ($a === 'mark') { $rest[] = '--mark'; continue; }
            $kinds[] = $alias[$a] ?? $a;
            continue;
        }
        $rest[] = $a;
    }
    [$pos, $f] = parseFlags($rest, ['site', 'kind', 'since', 'wait', 'n', 'level'], [], ['follow', 'mark']);
    if ($pos) throw new LeopardError('usage', 'Usage: leopard logs [--site NAME] [--kind php,access,fpm,web,db] [--since 10m|ISO|CURSOR] [--wait SECONDS] [-n N] [-f] | logs mark', EXIT_USAGE);
    $site = $f['site'] ?? null;
    if ($site !== null) needSite($site);
    if (isset($f['kind'])) $kinds = array_merge($kinds, explode(',', $f['kind']));
    if (!$kinds) $kinds = $site ? ['php', 'access'] : ['php', 'access', 'fpm', 'web', 'db'];
    foreach (['n', 'wait'] as $k) {
        if (isset($f[$k]) && !ctype_digit($f[$k])) throw new LeopardError('usage', "-$k needs a number", EXIT_USAGE);
    }
    $sources = logSources($site, array_unique($kinds));
    if (!empty($f['mark'])) {
        $c = [];
        foreach (logSources(null, ['php', 'access', 'fpm', 'web', 'db']) as [, $file]) { $st = stat($file); $c[$file] = [$st['ino'], $st['size']]; }
        $cur = encodeCursor($c);
        out(['cursor' => $cur], "$cur\n");
        return;
    }
    $cursor = null;
    $sinceTs = null;
    if (isset($f['since'])) {
        $since = $f['since'];
        if (preg_match('/^(\d+)([smhd])$/', $since, $m)) $sinceTs = time() - (int) $m[1] * ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400][$m[2]];
        elseif (($t = strtotime($since)) !== false && preg_match('/^\d{4}-/', $since)) $sinceTs = $t;
        elseif (($c = decodeCursor($since)) !== null) $cursor = $c;
        else throw new LeopardError('usage', "--since takes a duration (10m), a time (2026-11-04T10:00Z), or a cursor from `leopard logs mark`: $since", EXIT_USAGE);
    }
    $limit = isset($f['n']) ? (int) $f['n'] : ($cursor !== null || $sinceTs !== null ? 1000 : 50);
    $pick = function (array $entries) use ($site, $sinceTs, $f) {
        return array_values(array_filter($entries, fn($e) => ($site === null || $e['site'] === $site)
            && ($sinceTs === null || ($e['ts'] ?? 0) >= $sinceTs)
            && (!isset($f['level']) || $e['level'] === $f['level'])));
    };
    $show = function (array $e): array {
        $e['time'] = $e['ts'] !== null ? gmdate('Y-m-d\TH:i:s', (int) $e['ts']) . sprintf('.%03dZ', (int) round(fmod($e['ts'], 1) * 1000)) : null;
        unset($e['ts']);
        return ['time' => $e['time']] + $e;
    };
    $line = fn($e) => ($e['time'] ?? '-') . ' ' . $e['kind'] . ($e['site'] ? " {$e['site']}" : '') . " {$e['level']}: {$e['message']}\n";
    if (!empty($f['follow'])) {
        [$entries, $cur] = readLogs($sources, $cursor);
        $entries = array_slice($pick($entries), -$limit);
        while (true) {
            foreach ($entries as $e) {
                $e = $show($e);
                echo $json ? json_encode($e, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n" : $line($e);
            }
            usleep(300000);
            // New files (a new site) join the stream.
            $sources = logSources($site, array_unique($kinds));
            [$entries, $cur] = readLogs($sources, $cur);
            $entries = $pick($entries);
        }
    }
    $deadline = microtime(true) + (int) ($f['wait'] ?? 0);
    while (true) {
        [$entries, $cur, $reset] = readLogs($sources, $cursor);
        $entries = $pick($entries);
        if ($entries || $cursor === null || microtime(true) >= $deadline) break;
        usleep(200000);
        $sources = logSources($site, array_unique($kinds));
    }
    $entries = array_map($show, array_slice($entries, -$limit));
    // The cursor covers every file, so the next call can pass it for any kind.
    $all = $cursor ?? [];
    foreach ($cur as $file => $c) $all[$file] = $c;
    out(['entries' => $entries, 'cursor' => encodeCursor($all), 'reset' => $reset], implode('', array_map($line, $entries)));
}

// ---------------------------------------------------------------- db

function dbVersion(string $engine): string
{
    if ($engine === 'mariadb') return versions()['mariadb'];
    $p = installedPacks('mysql');
    foreach ($p as $x) if ("mysql-{$x['line']}" === $engine || "mysql-{$x['version']}" === $engine) return $x['version'];
    return '';
}

function dbCmd(array $args): void
{
    global $H, $R, $sock;
    [$pos, $f] = parseFlags($args, [], [], ['available', 'copy-data']);
    $sub = $pos[0] ?? 'list';
    $active = cfg()['db'];
    switch ($sub) {
        case 'list':
            $engines = [['engine' => 'mariadb', 'version' => versions()['mariadb'], 'installed' => true]];
            foreach (installedPacks('mysql') as $p) $engines[] = ['engine' => "mysql-{$p['line']}", 'version' => $p['version'], 'installed' => true];
            if (!empty($f['available'])) {
                foreach (packIndex()['packs'] as $p) {
                    if ($p['kind'] === 'mysql' && !packMeta($p['id'])) $engines[] = ['engine' => "mysql-{$p['line']}", 'version' => $p['version'], 'installed' => false];
                }
            }
            foreach ($engines as &$e) { $e['active'] = $e['engine'] === $active; $e['has_data'] = is_dir(dbDataDir($e['engine'])); }
            unset($e);
            out(['engines' => $engines], implode('', array_map(fn($e) => "{$e['engine']}  {$e['version']}" . ($e['active'] ? '  (active)' : '')
                . ($e['installed'] ? '' : '  (available: leopard db use ' . $e['engine'] . ')') . "\n", $engines)));
            return;
        case 'use':
            if (count($pos) !== 2) throw new LeopardError('usage', 'Usage: leopard db use mariadb|mysql-8.4 [--copy-data]', EXIT_USAGE);
            $engine = $pos[1];
            if ($engine !== 'mariadb') {
                if (!preg_match('/^mysql-[0-9.]+$/', $engine)) throw new LeopardError('usage', "Unknown engine: $engine. Use mariadb or mysql-<version>, such as mysql-8.4.", EXIT_USAGE);
                $engine = 'mysql-' . needDbPack($engine)['line'];
            }
            if ($engine === $active) {
                out(['db' => ['engine' => $engine, 'version' => dbVersion($engine)], 'changed' => false, 'copied' => []], "Already using $engine.\n");
                return;
            }
            $lock = stateLock();
            $state = loadState();
            $copied = [];
            $dump = null;
            if (!empty($f['copy-data'])) {
                if (!alive($state['db'] ?? null)) throw new LeopardError('not_running', 'The database must run to copy its data.', EXIT_NOT_RUNNING, 'Run `leopard start` first.');
                $db = db();
                foreach ($db->query('SHOW DATABASES')->fetch_all() as [$n]) {
                    if (!in_array($n, ['information_schema', 'mysql', 'performance_schema', 'sys'], true)) $copied[] = $n;
                }
                @mkdir("$H/db/tmp", 0777, true);
                $dump = "$H/db/tmp/copy-" . getmypid() . '.sql';
                [$code, , $err] = run(['sh', '-c', 'exec "$0" "$@" >"' . $dump . '"', "$R/bin/mariadb-dump", "--socket=$sock", '-uroot',
                    '--single-transaction', '--routines', '--events', '--skip-ssl-verify-server-cert', '--databases', ...$copied], null, false);
                if ($code !== 0) { @unlink($dump); throw new LeopardError('copy_failed', "Could not dump the databases: $err"); }
                // MariaDB 11 and MySQL 8 name their default collations differently.
                $sql = file_get_contents($dump);
                $sql = str_starts_with($engine, 'mysql')
                    ? preg_replace('/utf8mb4_uca1400_\w+/', 'utf8mb4_0900_ai_ci', $sql)
                    : preg_replace('/utf8mb4_0900_\w+/', 'utf8mb4_uca1400_ai_ci', $sql);
                file_put_contents($dump, $sql);
            }
            $s = savedSettings();
            $s['db'] = $engine;
            saveSettings($s);
            try {
                applyDb($state, $engine, cfg()['db_port']);
            } catch (Throwable $e) {
                $s['db'] = $active;
                saveSettings($s);
                saveState($state);
                if ($dump) @unlink($dump);
                throw $e;
            } finally {
                saveState($state);
            }
            if ($dump) {
                [$code, , $err] = run(['sh', '-c', 'exec "$0" "$@" <"' . $dump . '"', "$R/bin/mariadb", "--socket=$sock", '-uroot', '--skip-ssl-verify-server-cert'], null, false);
                @unlink($dump);
                if ($code !== 0) throw new LeopardError('copy_failed', "Switched to $engine, but importing the data failed: $err", EXIT_ERROR,
                    "The data in $active is untouched. Run `leopard db use $active` to go back.");
            }
            out(['db' => ['engine' => $engine, 'version' => dbVersion($engine)], 'changed' => true, 'copied' => $copied],
                "Now using $engine " . dbVersion($engine) . '.' . ($copied ? ' Copied: ' . implode(', ', $copied) . '.' : " Sites whose databases are on $active need --copy-data to work here.") . "\n");
            return;
        case 'prune':
            if (count($pos) !== 2) throw new LeopardError('usage', 'Usage: leopard db prune ENGINE', EXIT_USAGE);
            $engine = $pos[1];
            if ($engine === $active) throw new LeopardError('in_use', "$engine is the active database.", EXIT_ERROR, 'Switch with `leopard db use` first.');
            if (!preg_match('/^(mariadb|mysql-[0-9.]+)$/', $engine)) throw new LeopardError('usage', "Unknown engine: $engine", EXIT_USAGE);
            $dir = dbDataDir($engine);
            $had = is_dir($dir);
            if ($had) run(['rm', '-rf', '--', $dir]);
            out(['deleted' => $had ? $dir : null, 'changed' => $had], $had ? "Deleted the $engine data folder $dir.\n" : "$engine has no data folder.\n");
            return;
    }
    throw new LeopardError('usage', "Unknown db command: $sub. Use list, use, or prune.", EXIT_USAGE);
}

// ---------------------------------------------------------------- run

// Starts everything, then stays in the foreground: every 2 seconds it checks
// each server and restarts any that died. SIGTERM or Ctrl-C stops them all.
// Meant as a container's main process or a systemd service. After 5 failed
// repairs in a row it exits 1, so the container or systemd restarts it.
function runCmd(array $args): never
{
    global $json;
    $json = false;
    cmdStart($args);
    $stop = false;
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT, SIGHUP] as $sig) pcntl_signal($sig, function () use (&$stop) { $stop = true; });
    $fails = 0;
    while (!$stop) {
        // As a container's PID 1, this process must reap the servers it started.
        while (pcntl_waitpid(-1, $status, WNOHANG) > 0);
        for ($i = 0; $i < 20 && !$stop; $i++) usleep(100000);
        if ($stop) break;
        $st = loadState();
        $dead = !alive($st['web'] ?? null) || !alive($st['db'] ?? null) || array_filter($st['fpm'], fn($m) => !alive($m));
        if (!$dead) { $fails = 0; continue; }
        try {
            fwrite(STDERR, gmdate('c') . " leopard run: a server stopped; restarting it\n");
            startStack(docroot());
            $fails = 0;
        } catch (Throwable $e) {
            fwrite(STDERR, gmdate('c') . ' leopard run: ' . $e->getMessage() . "\n");
            if (++$fails >= 5) { stopStack(); exit(EXIT_ERROR); }
            sleep(min(30, 2 ** $fails));
        }
    }
    stopStack();
    fwrite(STDERR, "leopard run: stopped\n");
    exit(0);
}

// ---------------------------------------------------------------- agent skill

// `skill setup` writes a short stub where agents look for skills. The stub
// asks this Leopard for the guide, so a newer Leopard file updates what agents
// read without another setup. Like `service install`, it writes outside
// $LEOPARD_HOME, and only when asked.
const SKILL_MARK = 'leopard-stub:';

// The folders agents read skills from. Codex, Amp, Pi, and OpenCode share
// ~/.agents/skills; Claude Code has its own.
function skillTargets(): array
{
    $home = getenv('HOME');
    $dirs = ["$home/.agents/skills"];
    if (is_dir("$home/.claude")) $dirs[] = "$home/.claude/skills";
    $byReal = [];
    foreach ($dirs as $d) $byReal[realpath($d) ?: $d] ??= "$d/using-leopard";
    return array_values($byReal);
}

function skillStub(): string
{
    global $H, $R;
    preg_match('/^description:.*$/m', file_get_contents("$R/SKILL.md"), $m);
    return "---\nname: using-leopard\n{$m[0]}\nmetadata:\n  " . SKILL_MARK . " \"1\"\n---\n"
        . "<!-- Installed by \"leopard skill setup\". Remove it with \"leopard skill remove\". -->\n\n"
        . "Run `$H/bin/leopard skill show` and follow the guide it prints. The guide\n"
        . "comes from the installed Leopard, so it describes the commands that version has.\n\n"
        . "If that file is missing, Leopard was moved or removed. Try `leopard skill show`.\n";
}

// missing, installed, outdated (an older stub), or conflict (not ours).
function skillState(string $dir, string $stub): string
{
    $s = @file_get_contents("$dir/SKILL.md");
    if ($s === false) return file_exists($dir) || is_link($dir) ? 'conflict' : 'missing';
    if (!str_contains($s, SKILL_MARK)) return 'conflict';
    return $s === $stub ? 'installed' : 'outdated';
}

function skillCmd(array $args): void
{
    global $H, $R;
    [$pos, $f] = parseFlags($args, [], [], ['dry-run', 'force']);
    $sub = $pos[0] ?? 'status';
    if (count($pos) > 1 || !in_array($sub, ['status', 'setup', 'remove'], true)) {
        throw new LeopardError('usage', 'Usage: leopard skill [show | status | setup [--dry-run] [--force] | remove [--dry-run]]', EXIT_USAGE);
    }
    $stub = skillStub();
    $items = array_map(fn($d) => ['path' => $d, 'state' => skillState($d, $stub)], skillTargets());
    $dry = isset($f['dry-run']);

    if ($sub === 'setup') {
        $conflicts = array_filter($items, fn($i) => $i['state'] === 'conflict');
        if ($conflicts && !isset($f['force'])) {
            throw new LeopardError('exists', 'A skill named using-leopard that Leopard did not install is already at ' . implode(', ', array_column($conflicts, 'path')) . '.',
                EXIT_EXISTS, 'Pass --force to replace it with the stub.');
        }
        // The stub runs $H/bin/leopard. `leopard restart` keeps that link current.
        if (!$dry && !is_link("$H/bin/leopard")) run(["$R/leopard", 'link'], null, false);
        foreach ($items as &$i) {
            $i['action'] = ['missing' => 'created', 'outdated' => 'updated', 'conflict' => 'replaced', 'installed' => 'unchanged'][$i['state']];
            if ($dry || $i['action'] === 'unchanged') continue;
            if ($i['state'] === 'conflict') run(['rm', '-rf', '--', $i['path']]);
            @mkdir($i['path'], 0777, true);
            writeAtomic("{$i['path']}/SKILL.md", $stub);
            $i['state'] = 'installed';
        }
    } elseif ($sub === 'remove') {
        foreach ($items as &$i) {
            $ours = in_array($i['state'], ['installed', 'outdated'], true);
            $i['action'] = $ours ? 'removed' : ($i['state'] === 'conflict' ? 'skipped: not installed by Leopard' : 'unchanged');
            if ($dry || !$ours) continue;
            unlink("{$i['path']}/SKILL.md");
            @rmdir($i['path']);
            $i['state'] = 'missing';
        }
    }
    unset($i);
    $text = ($dry ? "Dry run: nothing was changed.\n" : '')
        . implode('', array_map(fn($i) => sprintf("%-10s %s%s\n", $i['state'], $i['path'], isset($i['action']) ? " ({$i['action']})" : ''), $items));
    if ($sub === 'status' && in_array('conflict', array_column($items, 'state'), true)) $text .= "conflict: a skill there was not installed by Leopard. `leopard skill setup --force` replaces it.\n";
    out(['skills' => $items, 'dry_run' => $dry], $text);
}

function main(array $args): void
{
    prepare();
    $cmd = $args[0] ?? '';
    $rest = array_slice($args, 1);
    switch ($cmd) {
        case 'start': cmdStart($rest); return;
        case 'restart': cmdStart([], true); return;
        case 'stop':
            stopStack();
            status();
            return;
        case 'status': status(); return;
        case 'config': config($rest); return;
        case 'doctor': doctor(); return;
        case 'xdebug': xdebugCmd($rest); return;
        case 'php': phpCmd($rest); return;
        case 'packs': packsCmd($rest); return;
        case 'logs': logsCmd($rest); return;
        case 'db': dbCmd($rest); return;
        case 'skill': skillCmd($rest); return;
        case 'run': runCmd($rest);
        case 'prepare': return;
        case 'version':
            $v = versions();
            out(['versions' => $v], implode('', array_map(fn($k, $x) => "$k $x\n", array_keys($v), $v)));
            return;
        case 'site':
            $sub = $rest[0] ?? 'list';
            $rest = array_slice($rest, 1);
            switch ($sub) {
                case 'create': siteCreate($rest); return;
                case 'delete': siteDelete($rest); return;
                case 'set': siteSet($rest); return;
                case 'wp': case 'php': siteExec($sub, $rest);
                case 'list':
                    $sites = array_map('siteView', allSites());
                    out(['sites' => $sites], $sites ? implode('', array_map(fn($s) => "{$s['name']}  {$s['url']}/  {$s['dir']}  php {$s['runs']['php']}\n", $sites)) : "No sites. Make one with `leopard site create NAME`.\n");
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
            throw new LeopardError('usage', "Unknown site command: $sub. Use create, list, info, path, set, delete, wp, or php.", EXIT_USAGE);
    }
    throw new LeopardError('usage', "Unknown command: $cmd. Run `leopard help`.", EXIT_USAGE);
}

try {
    main($args);
} catch (LeopardError $e) {
    $err = ['code' => $e->errorCode, 'message' => $e->getMessage(), 'hint' => $e->hint, 'retryable' => $e->exit === EXIT_BUSY, 'log' => $e->log];
    if ($e->log) $err['log_tail'] = tailOf($e->log, 10);
    if ($json) {
        echo json_encode(['ok' => false, 'api' => API, 'error' => array_filter($err, fn($v) => $v !== null)], JSON_FLAGS), "\n";
    } else {
        fwrite(STDERR, 'leopard: ' . $e->getMessage() . "\n" . ($e->hint ? "hint: {$e->hint}\n" : '') . ($e->log ? "log: {$e->log}\n" . tailOf($e->log, 10) . "\n" : ''));
    }
    exit($e->exit);
} catch (Throwable $e) {
    $msg = get_class($e) . ': ' . $e->getMessage();
    if ($json) echo json_encode(['ok' => false, 'api' => API, 'error' => ['code' => 'internal', 'message' => $msg]], JSON_FLAGS), "\n";
    else fwrite(STDERR, "leopard: $msg\n");
    exit(EXIT_ERROR);
}
