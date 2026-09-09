<?php

declare(strict_types=1);

/**
 * Web-request column for benchmark suite v2 (#36385).
 *
 * Times MiniWebApp `/index.php` (home) and `/index.php/api/status` under:
 *   - Zend `php -S` (builtin server)
 *   - `phpc serve` (VM web)
 *   - `phpc serve --aot` when `.phpc/bin/app` already exists (no cold project build)
 *   - `php-fpm` when a usable binary is on PATH (otherwise n/a with reason)
 *
 * Usage (pinned env):
 *   ./script/docker-exec.sh -- bash -lc 'PHP_8_2=$(command -v php) php script/bench-web-request.php'
 *   PHP_COMPILER_BENCH_WEB_REQUESTS=2000 PHP_8_2=$(command -v php) php script/bench-web-request.php --json
 *
 * Does not hang forever: per-server ready timeout + curl max-time. Skips cleanly when
 * loopback bind fails or PHP_COMPILER_SKIP_SERVE_TESTS is set.
 */

$root = dirname(__DIR__);
$argvList = $argv ?? [];
$jsonOnly = in_array('--json', $argvList, true);
$mergeResults = in_array('--merge-results', $argvList, true);

$php = getenv('PHP_8_2') ?: (getenv('PHP_8_1') ?: '');
if (!is_string($php) || '' === $php || !is_executable($php)) {
    foreach (['php'] as $candidate) {
        $which = trim((string) shell_exec('command -v '.escapeshellarg($candidate).' 2>/dev/null'));
        if ('' !== $which && is_executable($which)) {
            $php = $which;
            break;
        }
    }
}
if (!is_string($php) || '' === $php || !is_executable($php)) {
    fwrite(STDERR, "bench-web-request: need PHP_8_2=/path/to/php\n");
    exit(1);
}

$requestsEnv = getenv('PHP_COMPILER_BENCH_WEB_REQUESTS');
$requests = is_string($requestsEnv) && ctype_digit($requestsEnv) ? (int) $requestsEnv : 200;
if ($requests < 1) {
    $requests = 1;
}

$project = $root.'/examples/003-MiniWebApp';
$docroot = $project.'/public';
if (!is_dir($docroot) || !is_file($docroot.'/index.php')) {
    fwrite(STDERR, "bench-web-request: MiniWebApp public/index.php missing\n");
    exit(1);
}

$payload = [
    'name' => 'web-request',
    'suite' => 'v2',
    'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'requests' => $requests,
    'routes' => [
        ['path' => '/index.php', 'needle' => 'MiniWebApp'],
        ['path' => '/index.php/api/status', 'needle' => '"ok":true'],
    ],
    'zend_builtin_s' => null,
    'phpc_serve_s' => null,
    'phpc_serve_aot_s' => null,
    'php_fpm_s' => null,
    'req_per_s' => [
        'zend_builtin' => null,
        'phpc_serve' => null,
        'phpc_serve_aot' => null,
        'php_fpm' => null,
    ],
    'p99_ms' => [
        'zend_builtin' => null,
        'phpc_serve' => null,
        'phpc_serve_aot' => null,
        'php_fpm' => null,
    ],
    'notes' => [],
];

if ('' !== (string) getenv('PHP_COMPILER_SKIP_SERVE_TESTS')) {
    $payload['notes'][] = 'skipped: PHP_COMPILER_SKIP_SERVE_TESTS is set';
    emitAndExit($payload, $jsonOnly, $mergeResults, $root, 0);
}

if (!canBindLoopback($root, $php)) {
    $payload['notes'][] = 'skipped: cannot bind loopback TCP';
    emitAndExit($payload, $jsonOnly, $mergeResults, $root, 0);
}

if (!commandExists('curl')) {
    fwrite(STDERR, "bench-web-request: curl is required\n");
    exit(1);
}

$phpc = $root.'/phpc';
if (!is_file($phpc)) {
    fwrite(STDERR, "bench-web-request: missing {$phpc}\n");
    exit(1);
}

// Zend builtin server
$zend = measureServer(
    $php,
    [$php, '-S', 'HOSTPORT', '-t', $docroot],
    $payload['routes'],
    $requests,
    'zend_builtin'
);
mergeMeasure($payload, 'zend_builtin', $zend);

// phpc serve (VM)
$vm = measureServer(
    $php,
    [$phpc, 'serve', 'HOSTPORT', $project],
    $payload['routes'],
    $requests,
    'phpc_serve'
);
mergeMeasure($payload, 'phpc_serve', $vm);

// phpc serve --aot only when a prior project binary still serves home + api/status.
// Home-only CLI probe is not enough: a stale Aug-2026 MiniWebApp binary printed
// MiniWebApp on CLI home but returned `{}` for PATH_INFO=/api/status (#36385).
$aotBin = $project.'/.phpc/bin/app';
if (is_executable($aotBin)) {
    $probeHome = trim((string) shell_exec(escapeshellarg($aotBin).' 2>/dev/null'));
    $probeApiCmd = 'env REQUEST_METHOD=GET SCRIPT_NAME=/index.php PATH_INFO=/api/status '
        .'QUERY_STRING= SERVER_PROTOCOL=HTTP/1.1 '
        .escapeshellarg($aotBin).' 2>/dev/null';
    $probeApi = trim((string) shell_exec($probeApiCmd));
    if (str_contains($probeHome, 'MiniWebApp') && str_contains($probeApi, '"ok":true')) {
        $aot = measureServer(
            $php,
            [$phpc, 'serve', '--aot', 'HOSTPORT', $project],
            $payload['routes'],
            $requests,
            'phpc_serve_aot'
        );
        mergeMeasure($payload, 'phpc_serve_aot', $aot);
    } else {
        $payload['notes'][] = 'phpc_serve_aot n/a: .phpc/bin/app stale or incomplete '
            .'(need MiniWebApp on home + "ok":true on PATH_INFO=/api/status; rebuild with phpc build --project)';
    }
} else {
    $payload['notes'][] = 'phpc_serve_aot n/a: examples/003-MiniWebApp/.phpc/bin/app missing (build with phpc build --project first)';
}

// php-fpm: when present, run a disposable pool + pure-PHP FastCGI client (#36385).
// No HTTP front required — measures FastCGI wall time with the same route needles.
$fpm = trim((string) shell_exec('command -v php-fpm 2>/dev/null'));
if ('' === $fpm) {
    $fpm = trim((string) shell_exec('command -v php-fpm8.2 2>/dev/null'));
}
if ('' === $fpm || !is_executable($fpm)) {
    $payload['notes'][] = 'php_fpm n/a: no php-fpm binary on PATH in this environment';
} else {
    $fpmMeasure = measurePhpFpm($fpm, $php, $docroot, $payload['routes'], $requests);
    mergeMeasure($payload, 'php_fpm', $fpmMeasure);
}

emitAndExit($payload, $jsonOnly, $mergeResults, $root, 0);

/**
 * @param array<string, mixed> $payload
 */
function emitAndExit(array $payload, bool $jsonOnly, bool $mergeResults, string $root, int $code): void
{
    $json = json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n";
    $outPath = $root.'/benchmarks/v2/WEB_REQUEST.json';
    file_put_contents($outPath, $json);

    if ($mergeResults) {
        mergeIntoResults($root, $payload);
    }

    if ($jsonOnly) {
        echo $json;
        exit($code);
    }

    echo "bench-web-request (#36385): {$payload['requests']} paired requests (home + api/status)\n";
    foreach (['zend_builtin', 'phpc_serve', 'phpc_serve_aot', 'php_fpm'] as $key) {
        $wallKey = $key.'_s';
        if ('zend_builtin' === $key) {
            $wallKey = 'zend_builtin_s';
        } elseif ('phpc_serve' === $key) {
            $wallKey = 'phpc_serve_s';
        } elseif ('phpc_serve_aot' === $key) {
            $wallKey = 'phpc_serve_aot_s';
        } else {
            $wallKey = 'php_fpm_s';
        }
        $wall = $payload[$wallKey] ?? null;
        $rps = $payload['req_per_s'][$key] ?? null;
        $p99 = $payload['p99_ms'][$key] ?? null;
        if (null === $wall) {
            echo sprintf("  %-16s n/a\n", $key);
        } else {
            echo sprintf(
                "  %-16s wall=%.4fs  req/s=%.1f  p99=%.1fms\n",
                $key,
                $wall,
                $rps ?? 0.0,
                $p99 ?? 0.0
            );
        }
    }
    foreach ($payload['notes'] as $note) {
        echo "  note: {$note}\n";
    }
    echo "Wrote {$outPath}\n";
    exit($code);
}

/**
 * @param array<string, mixed> $payload
 */
function mergeIntoResults(string $root, array $payload): void
{
    $resultsPath = $root.'/benchmarks/v2/RESULTS.json';
    $doc = [];
    if (is_file($resultsPath)) {
        $decoded = json_decode((string) file_get_contents($resultsPath), true);
        if (is_array($decoded)) {
            $doc = $decoded;
        }
    }
    if (!isset($doc['cases']) || !is_array($doc['cases'])) {
        $doc['cases'] = [];
    }
    $doc['web_request'] = $payload;
    $doc['generated_at'] = gmdate('Y-m-d\TH:i:s\Z');
    file_put_contents($resultsPath, json_encode($doc, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");
    echo "Merged web_request into {$resultsPath}\n";
}

/**
 * @param list<array{path: string, needle: string}> $routes
 * @param list<string> $cmdTemplate HOSTPORT placeholder
 * @return array{wall_s: ?float, req_per_s: ?float, p99_ms: ?float, note: ?string}
 */
function measureServer(string $php, array $cmdTemplate, array $routes, int $requests, string $label): array
{
    $port = findFreePort($php);
    if (null === $port) {
        return ['wall_s' => null, 'req_per_s' => null, 'p99_ms' => null, 'note' => 'no free port'];
    }
    $hostport = '127.0.0.1:'.$port;
    $cmd = [];
    foreach ($cmdTemplate as $part) {
        $cmd[] = 'HOSTPORT' === $part ? $hostport : $part;
    }
    $log = tempnam(sys_get_temp_dir(), 'phpcweb');
    if (false === $log) {
        return ['wall_s' => null, 'req_per_s' => null, 'p99_ms' => null, 'note' => 'temp log failed'];
    }

    $cmdLine = '';
    foreach ($cmd as $part) {
        $cmdLine .= ('' === $cmdLine ? '' : ' ').escapeshellarg($part);
    }
    $cmdLine .= ' >'.escapeshellarg($log).' 2>&1 & echo $!';
    $pid = (int) trim((string) shell_exec($cmdLine));
    if ($pid < 1) {
        @unlink($log);

        return ['wall_s' => null, 'req_per_s' => null, 'p99_ms' => null, 'note' => 'failed to spawn '.$label];
    }

    try {
        if (!waitForPort($port, (int) (getenv('PHP_COMPILER_SERVE_READY_TIMEOUT') ?: 30))) {
            return ['wall_s' => null, 'req_per_s' => null, 'p99_ms' => null, 'note' => $label.' did not become ready'];
        }

        // Warmup + output verify
        foreach ($routes as $route) {
            $url = 'http://127.0.0.1:'.$port.$route['path'];
            $body = curlBody($url, $status);
            if (200 !== $status || !str_contains($body, $route['needle'])) {
                return [
                    'wall_s' => null,
                    'req_per_s' => null,
                    'p99_ms' => null,
                    'note' => $label.' warmup failed status='.$status.' path='.$route['path'],
                ];
            }
        }

        $samplesMs = [];
        $start = microtime(true);
        for ($i = 0; $i < $requests; ++$i) {
            foreach ($routes as $route) {
                $url = 'http://127.0.0.1:'.$port.$route['path'];
                $t0 = microtime(true);
                $body = curlBody($url, $status);
                $samplesMs[] = (microtime(true) - $t0) * 1000.0;
                if (200 !== $status || !str_contains($body, $route['needle'])) {
                    return [
                        'wall_s' => null,
                        'req_per_s' => null,
                        'p99_ms' => null,
                        'note' => $label.' request '.$i.' failed status='.$status,
                    ];
                }
            }
        }
        $wall = microtime(true) - $start;
        $totalReqs = $requests * count($routes);
        sort($samplesMs);
        $idx = (int) max(0, (int) floor(0.99 * (count($samplesMs) - 1)));

        return [
            'wall_s' => $wall,
            'req_per_s' => $wall > 0.0 ? $totalReqs / $wall : null,
            'p99_ms' => $samplesMs[$idx] ?? null,
            'note' => null,
        ];
    } finally {
        stopPid($pid);
        @unlink($log);
    }
}

/**
 * @param array<string, mixed> $payload
 * @param array{wall_s: ?float, req_per_s: ?float, p99_ms: ?float, note: ?string} $m
 */
function mergeMeasure(array &$payload, string $key, array $m): void
{
    $wallKey = match ($key) {
        'zend_builtin' => 'zend_builtin_s',
        'phpc_serve' => 'phpc_serve_s',
        'phpc_serve_aot' => 'phpc_serve_aot_s',
        default => 'php_fpm_s',
    };
    $payload[$wallKey] = $m['wall_s'];
    $payload['req_per_s'][$key] = $m['req_per_s'];
    $payload['p99_ms'][$key] = $m['p99_ms'];
    if (null !== $m['note']) {
        $payload['notes'][] = $m['note'];
    }
}

function canBindLoopback(string $root, string $php): bool
{
    $probe = $root.'/script/can-bind-loopback.php';
    if (!is_file($probe)) {
        return true;
    }
    exec(escapeshellcmd($php).' '.escapeshellarg($probe).' >/dev/null 2>&1', $o, $rc);

    return 0 === $rc;
}

function commandExists(string $name): bool
{
    $which = trim((string) shell_exec('command -v '.escapeshellarg($name).' 2>/dev/null'));

    return '' !== $which;
}

function findFreePort(string $php): ?int
{
    $code = '$s=@stream_socket_server("tcp://127.0.0.1:0",$e,$r);'
        .'if($s===false){exit(1);} $n=stream_socket_get_name($s,false); fclose($s);'
        .'if(!preg_match("#:(\\d+)$#",$n,$m)){exit(1);} echo $m[1];';
    $out = trim((string) shell_exec(escapeshellcmd($php).' -r '.escapeshellarg($code).' 2>/dev/null'));
    if (!ctype_digit($out)) {
        return null;
    }

    return (int) $out;
}

function waitForPort(int $port, int $timeoutSec): bool
{
    $deadline = microtime(true) + $timeoutSec;
    while (microtime(true) < $deadline) {
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if (false !== $fp) {
            fclose($fp);

            return true;
        }
        usleep(50000);
    }

    return false;
}

function curlBody(string $url, ?int &$status = null): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'curlbody');
    if (false === $tmp) {
        $status = 0;

        return '';
    }
    $cmd = 'curl -sS -o '.escapeshellarg($tmp).' -w "%{http_code}"'
        .' --connect-timeout 5 --max-time 15 '.escapeshellarg($url);
    $code = trim((string) shell_exec($cmd.' 2>/dev/null'));
    $status = ctype_digit($code) ? (int) $code : 0;
    $body = (string) file_get_contents($tmp);
    @unlink($tmp);

    return $body;
}

function stopPid(int $pid): void
{
    if ($pid < 1) {
        return;
    }
    exec('kill -TERM '.((int) $pid).' 2>/dev/null');
    $deadline = microtime(true) + 2.0;
    while (microtime(true) < $deadline) {
        $alive = trim((string) shell_exec('kill -0 '.((int) $pid).' 2>/dev/null; echo $?'));
        if ('0' !== $alive) {
            return;
        }
        usleep(50000);
    }
    exec('kill -KILL '.((int) $pid).' 2>/dev/null');
}

/**
 * Disposable php-fpm pool + FastCGI client for the web-request column (#36385).
 *
 * @param list<array{path: string, needle: string}> $routes
 * @return array{wall_s: ?float, req_per_s: ?float, p99_ms: ?float, note: ?string}
 */
function measurePhpFpm(string $fpmBin, string $php, string $docroot, array $routes, int $requests): array
{
    $port = findFreePort($php);
    if (null === $port) {
        return ['wall_s' => null, 'req_per_s' => null, 'p99_ms' => null, 'note' => 'php_fpm: no free port'];
    }
    $tmp = sys_get_temp_dir().'/phpc-fpm-'.getmypid().'-'.$port;
    if (!mkdir($tmp, 0700, true) && !is_dir($tmp)) {
        return ['wall_s' => null, 'req_per_s' => null, 'p99_ms' => null, 'note' => 'php_fpm: temp dir failed'];
    }
    $conf = $tmp.'/php-fpm.conf';
    $errorLog = $tmp.'/error.log';
    $poolLog = $tmp.'/pool.log';
    $index = $docroot.'/index.php';
    $confBody = <<<CONF
[global]
pid = {$tmp}/php-fpm.pid
error_log = {$errorLog}
daemonize = no

[www]
user = nobody
group = nogroup
listen = 127.0.0.1:{$port}
listen.allowed_clients = 127.0.0.1
pm = static
pm.max_children = 2
catch_workers_output = yes
php_admin_value[error_log] = {$poolLog}
php_admin_flag[log_errors] = on
chdir = {$docroot}
CONF;
    // Prefer running as current user when nobody is unavailable (containers).
    $uid = function_exists('posix_geteuid') ? (int) posix_geteuid() : 0;
    if (0 !== $uid) {
        $user = function_exists('posix_getpwuid') ? (posix_getpwuid($uid)['name'] ?? 'www-data') : 'www-data';
        $gid = function_exists('posix_getegid') ? (int) posix_getegid() : $uid;
        $group = function_exists('posix_getgrgid') ? (posix_getgrgid($gid)['name'] ?? $user) : $user;
        $confBody = str_replace(
            ["user = nobody", "group = nogroup"],
            ["user = {$user}", "group = {$group}"],
            $confBody
        );
    }
    file_put_contents($conf, $confBody."\n");

    $cmdLine = escapeshellarg($fpmBin).' -F -y '.escapeshellarg($conf)
        .' >'.escapeshellarg($tmp.'/stdout.log').' 2>&1 & echo $!';
    $pid = (int) trim((string) shell_exec($cmdLine));
    if ($pid < 1) {
        cleanupFpmTemp($tmp);

        return ['wall_s' => null, 'req_per_s' => null, 'p99_ms' => null, 'note' => 'php_fpm: failed to spawn'];
    }

    try {
        if (!waitForPort($port, (int) (getenv('PHP_COMPILER_SERVE_READY_TIMEOUT') ?: 30))) {
            $err = is_file($errorLog) ? trim((string) file_get_contents($errorLog)) : '';

            return [
                'wall_s' => null,
                'req_per_s' => null,
                'p99_ms' => null,
                'note' => 'php_fpm did not become ready'.('' !== $err ? ': '.$err : ''),
            ];
        }

        foreach ($routes as $route) {
            $body = fastcgiGet($port, $docroot, $index, $route['path'], $status);
            if (200 !== $status || !str_contains($body, $route['needle'])) {
                return [
                    'wall_s' => null,
                    'req_per_s' => null,
                    'p99_ms' => null,
                    'note' => 'php_fpm warmup failed status='.$status.' path='.$route['path'],
                ];
            }
        }

        $samplesMs = [];
        $start = microtime(true);
        for ($i = 0; $i < $requests; ++$i) {
            foreach ($routes as $route) {
                $t0 = microtime(true);
                $body = fastcgiGet($port, $docroot, $index, $route['path'], $status);
                $samplesMs[] = (microtime(true) - $t0) * 1000.0;
                if (200 !== $status || !str_contains($body, $route['needle'])) {
                    return [
                        'wall_s' => null,
                        'req_per_s' => null,
                        'p99_ms' => null,
                        'note' => 'php_fpm request '.$i.' failed status='.$status,
                    ];
                }
            }
        }
        $wall = microtime(true) - $start;
        $totalReqs = $requests * count($routes);
        sort($samplesMs);
        $idx = (int) max(0, (int) floor(0.99 * (count($samplesMs) - 1)));

        return [
            'wall_s' => $wall,
            'req_per_s' => $wall > 0.0 ? $totalReqs / $wall : null,
            'p99_ms' => $samplesMs[$idx] ?? null,
            'note' => 'php_fpm via FastCGI (no HTTP front; compare to zend_builtin carefully)',
        ];
    } finally {
        stopPid($pid);
        cleanupFpmTemp($tmp);
    }
}

function cleanupFpmTemp(string $tmp): void
{
    if (!is_dir($tmp)) {
        return;
    }
    foreach (glob($tmp.'/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($tmp);
}

/**
 * Minimal FastCGI GET against php-fpm (FCGI_BEGIN_REQUEST + FCGI_PARAMS + empty STDIN).
 */
function fastcgiGet(int $port, string $docroot, string $scriptFilename, string $path, ?int &$status = null): string
{
    $status = 0;
    $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 5.0);
    if (false === $fp) {
        return '';
    }
    stream_set_timeout($fp, 15);

    // PATH_INFO for MiniWebApp routes like /index.php/api/status
    $scriptName = '/index.php';
    $pathInfo = '';
    if (str_starts_with($path, '/index.php')) {
        $pathInfo = substr($path, strlen('/index.php'));
    } else {
        $scriptName = $path;
    }

    $params = [
        'REQUEST_METHOD' => 'GET',
        'SCRIPT_FILENAME' => $scriptFilename,
        'SCRIPT_NAME' => $scriptName,
        'REQUEST_URI' => $path,
        'PATH_INFO' => $pathInfo,
        'QUERY_STRING' => '',
        'DOCUMENT_ROOT' => $docroot,
        'SERVER_SOFTWARE' => 'phpc-bench-web-request',
        'SERVER_NAME' => '127.0.0.1',
        'SERVER_PORT' => '80',
        'REMOTE_ADDR' => '127.0.0.1',
        'SERVER_PROTOCOL' => 'HTTP/1.1',
        'GATEWAY_INTERFACE' => 'CGI/1.1',
        'CONTENT_LENGTH' => '0',
    ];

    $reqId = 1;
    $packets = '';
    $packets .= fcgiRecord(1, $reqId, "\x00\x01\x00\x00\x00\x00\x00\x00"); // BEGIN_REQUEST responder
    $paramBody = '';
    foreach ($params as $k => $v) {
        $paramBody .= fcgiNameValue($k, $v);
    }
    $packets .= fcgiRecord(4, $reqId, $paramBody); // PARAMS
    $packets .= fcgiRecord(4, $reqId, ''); // PARAMS end
    $packets .= fcgiRecord(5, $reqId, ''); // STDIN end
    fwrite($fp, $packets);

    $stdout = '';
    $deadline = microtime(true) + 15.0;
    while (microtime(true) < $deadline) {
        $header = '';
        while (strlen($header) < 8) {
            $chunk = fread($fp, 8 - strlen($header));
            if (false === $chunk || '' === $chunk) {
                break 2;
            }
            $header .= $chunk;
        }
        $fields = unpack('Cversion/Ctype/nrequestId/ncontentLength/CpaddingLength/Creserved', $header);
        if (!is_array($fields)) {
            break;
        }
        $len = (int) $fields['contentLength'];
        $pad = (int) $fields['paddingLength'];
        $content = '';
        while (strlen($content) < $len) {
            $chunk = fread($fp, $len - strlen($content));
            if (false === $chunk || '' === $chunk) {
                break 2;
            }
            $content .= $chunk;
        }
        if ($pad > 0) {
            fread($fp, $pad);
        }
        $type = (int) $fields['type'];
        if (6 === $type) { // STDOUT
            $stdout .= $content;
        } elseif (3 === $type) { // END_REQUEST
            break;
        }
    }
    fclose($fp);

    $headerEnd = strpos($stdout, "\r\n\r\n");
    if (false === $headerEnd) {
        $headerEnd = strpos($stdout, "\n\n");
    }
    if (false === $headerEnd) {
        return $stdout;
    }
    $rawHeaders = substr($stdout, 0, $headerEnd);
    $body = substr($stdout, $headerEnd);
    $body = ltrim($body, "\r\n");
    if (preg_match('/Status:\s*(\d+)/i', $rawHeaders, $m)) {
        $status = (int) $m[1];
    } else {
        $status = 200;
    }

    return $body;
}

function fcgiNameValue(string $name, string $value): string
{
    return fcgiLength(strlen($name)).fcgiLength(strlen($value)).$name.$value;
}

function fcgiLength(int $len): string
{
    if ($len < 128) {
        return chr($len);
    }

    return pack('N', $len | 0x80000000);
}

function fcgiRecord(int $type, int $requestId, string $content): string
{
    $len = strlen($content);
    $pad = (8 - ($len % 8)) % 8;

    return pack('CCnnCx', 1, $type, $requestId, $len, $pad).$content.str_repeat("\0", $pad);
}
