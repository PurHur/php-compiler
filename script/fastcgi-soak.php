#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * FastCGI VM soak — RSS / emalloc flatness across keep-alive requests (#36388).
 *
 * Usage:
 *   php script/fastcgi-soak.php --requests=100
 *   php script/fastcgi-soak.php --requests=1000 --project=examples/003-MiniWebApp
 *   php script/fastcgi-soak.php --requests=100 --project=examples/004-ApiJson --write-json=benchmarks/v2/FASTCGI_SOAK.json
 *   ./script/fastcgi-smoke.sh --soak 100
 *
 * php-src: php_request_startup / php_request_shutdown tear down the request heap
 * each cycle; MemoryAccounting::beginRequest/endRequest must keep emalloc flat.
 */

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';

use PHPCompiler\VM\MemoryAccounting;
use PHPCompiler\Web\FastCgi\Record;
use PHPCompiler\Web\FastCgi\Request;
use PHPCompiler\Web\FastCgi\RequestHandler;
use PHPCompiler\Web\ProjectManifest;

$requests = 100;
$projectRel = 'examples/009-FastCGIWeb';
$writeJson = null;
foreach ($argv as $i => $arg) {
    if (0 === $i) {
        continue;
    }
    if (str_starts_with($arg, '--requests=')) {
        $requests = (int) substr($arg, strlen('--requests='));
    } elseif ('--requests' === $arg) {
        $requests = (int) ($argv[$i + 1] ?? 0);
    } elseif (str_starts_with($arg, '--project=')) {
        $projectRel = substr($arg, strlen('--project='));
    } elseif ('--project' === $arg) {
        $projectRel = (string) ($argv[$i + 1] ?? '');
    } elseif (str_starts_with($arg, '--write-json=')) {
        $writeJson = substr($arg, strlen('--write-json='));
    } elseif ('--write-json' === $arg) {
        $writeJson = (string) ($argv[$i + 1] ?? '');
    }
}
if ($requests < 2) {
    fwrite(STDERR, "fastcgi-soak: --requests must be >= 2\n");
    exit(2);
}
if ('' === $projectRel) {
    fwrite(STDERR, "fastcgi-soak: --project must be non-empty\n");
    exit(2);
}

$projectDir = $projectRel;
if (!str_starts_with($projectDir, '/')) {
    $projectDir = $root.'/'.ltrim($projectRel, '/');
}
$projectReal = realpath($projectDir);
if (false === $projectReal || !is_dir($projectReal)) {
    fwrite(STDERR, "fastcgi-soak: missing project dir {$projectDir}\n");
    exit(2);
}

$entry = ProjectManifest::resolveEntryPath($projectReal);
if (null === $entry) {
    // Bare script projects (009) use example.php beside phpc.json.
    $fallback = $projectReal.'/example.php';
    if (!is_file($fallback)) {
        fwrite(STDERR, "fastcgi-soak: no entry in {$projectReal}/phpc.json\n");
        exit(2);
    }
    $entry = $fallback;
}
$publicDir = ProjectManifest::resolvePublicDir($projectReal);
$scriptName = '/'.basename($entry);
$params = [
    'REQUEST_METHOD' => 'GET',
    'SCRIPT_FILENAME' => $entry,
    'SCRIPT_NAME' => $scriptName,
    'REQUEST_URI' => $scriptName,
    'DOCUMENT_ROOT' => $publicDir,
    'QUERY_STRING' => '',
    'CONTENT_LENGTH' => '0',
];

$handler = new RequestHandler($projectReal);
// Warm-up before baseline: early RSS climbs while Zend/autoload settle (#36388).
// Previously max(2, N/10) made --requests=20 baseline_at=2 and false-failed.
$baselineAt = min(100, max(10, intdiv($requests, 10)));
if ($baselineAt >= $requests) {
    $baselineAt = max(2, $requests - 1);
}
$rssAtBaseline = 0;
$emallocAtBaseline = 0;
$rssFinal = 0;
$emallocFinal = 0;

for ($i = 1; $i <= $requests; $i++) {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if (false === $pair) {
        fwrite(STDERR, "fastcgi-soak: stream_socket_pair failed\n");
        exit(1);
    }
    [$client, $server] = $pair;
    // One request per stream — KEEP_CONN would block waiting for the next record.
    fwrite($client, Request::encode(1, $params, '', Record::ROLE_RESPONDER, 0));
    $handler->handleStream($server);
    fclose($server);
    stream_get_contents($client);
    fclose($client);

    if ($i === $baselineAt) {
        $rssAtBaseline = readRssKb();
        $emallocAtBaseline = MemoryAccounting::currentBytes();
    }
    if ($i === $requests) {
        $rssFinal = readRssKb();
        $emallocFinal = MemoryAccounting::currentBytes();
    }
}

echo "fastcgi-soak: project={$projectRel} requests={$requests} baseline_at={$baselineAt}\n";
echo "fastcgi-soak: rss_kb baseline={$rssAtBaseline} final={$rssFinal}\n";
echo "fastcgi-soak: emalloc baseline={$emallocAtBaseline} final={$emallocFinal}\n";

$ok = true;
// Emalloc must stay at 0 after endRequest; allow tiny interpreter slop.
if ($emallocFinal > 4096) {
    fwrite(STDERR, "fastcgi-soak: FAIL emalloc final={$emallocFinal} (expected ≤4096 after endRequest)\n");
    $ok = false;
}

$rssLimit = 0;
if ($rssAtBaseline > 0 && $rssFinal > 0) {
    // Done-when (#36388): RSS after N within 5% of baseline (+1 MiB slack for allocator noise).
    $rssLimit = (int) ceil($rssAtBaseline * 1.05) + 1024;
    if ($rssFinal > $rssLimit) {
        fwrite(STDERR, "fastcgi-soak: FAIL rss final={$rssFinal} kb > limit={$rssLimit} kb (baseline={$rssAtBaseline})\n");
        $ok = false;
    }
}

if (null !== $writeJson && '' !== $writeJson) {
    $outPath = $writeJson;
    if (!str_starts_with($outPath, '/')) {
        $outPath = $root.'/'.ltrim($writeJson, '/');
    }
    $dir = dirname($outPath);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        fwrite(STDERR, "fastcgi-soak: cannot create {$dir}\n");
        exit(1);
    }
    $payload = [
        'generated_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
        'issue' => 36388,
        'project' => $projectRel,
        'requests' => $requests,
        'baseline_at' => $baselineAt,
        'rss_kb' => [
            'baseline' => $rssAtBaseline,
            'final' => $rssFinal,
            'limit' => $rssLimit,
        ],
        'emalloc' => [
            'baseline' => $emallocAtBaseline,
            'final' => $emallocFinal,
        ],
        'ok' => $ok,
    ];
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    if (false === file_put_contents($outPath, $json)) {
        fwrite(STDERR, "fastcgi-soak: failed to write {$outPath}\n");
        exit(1);
    }
    echo "fastcgi-soak: wrote {$outPath}\n";
}

if (!$ok) {
    exit(1);
}

echo "fastcgi-soak: OK\n";
exit(0);

function readRssKb(): int
{
    if ('Linux' !== PHP_OS_FAMILY || !is_readable('/proc/self/status')) {
        return 0;
    }
    $status = (string) file_get_contents('/proc/self/status');
    if (preg_match('/^VmRSS:\s+(\d+)\s+kB/m', $status, $m)) {
        return (int) $m[1];
    }

    return 0;
}
