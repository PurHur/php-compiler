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

// phpc serve --aot only when a prior project binary exists and still prints MiniWebApp
$aotBin = $project.'/.phpc/bin/app';
if (is_executable($aotBin)) {
    $probe = trim((string) shell_exec(escapeshellarg($aotBin).' 2>/dev/null'));
    if (str_contains($probe, 'MiniWebApp')) {
        $aot = measureServer(
            $php,
            [$phpc, 'serve', '--aot', 'HOSTPORT', $project],
            $payload['routes'],
            $requests,
            'phpc_serve_aot'
        );
        mergeMeasure($payload, 'phpc_serve_aot', $aot);
    } else {
        $payload['notes'][] = 'phpc_serve_aot n/a: .phpc/bin/app exists but CLI probe lacks MiniWebApp (rebuild with phpc build --project)';
    }
} else {
    $payload['notes'][] = 'phpc_serve_aot n/a: examples/003-MiniWebApp/.phpc/bin/app missing (build with phpc build --project first)';
}

// php-fpm: rare in the pinned image — report n/a with reason rather than inventing a column
$fpm = trim((string) shell_exec('command -v php-fpm 2>/dev/null'));
if ('' === $fpm) {
    $fpm = trim((string) shell_exec('command -v php-fpm8.2 2>/dev/null'));
}
if ('' === $fpm || !is_executable($fpm)) {
    $payload['notes'][] = 'php_fpm n/a: no php-fpm binary on PATH in this environment';
} else {
    $payload['notes'][] = 'php_fpm n/a: FastCGI pool wiring for MiniWebApp is not automated in this harness yet; use zend_builtin as the Zend reference';
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
