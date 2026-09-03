#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * FastCGI VM soak — RSS / emalloc flatness across keep-alive requests (#36388).
 *
 * Usage:
 *   php script/fastcgi-soak.php --requests=100
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

$requests = 100;
foreach ($argv as $i => $arg) {
    if (0 === $i) {
        continue;
    }
    if (str_starts_with($arg, '--requests=')) {
        $requests = (int) substr($arg, strlen('--requests='));
    } elseif ('--requests' === $arg) {
        $requests = (int) ($argv[$i + 1] ?? 0);
    }
}
if ($requests < 2) {
    fwrite(STDERR, "fastcgi-soak: --requests must be >= 2\n");
    exit(2);
}

$example = $root.'/examples/009-FastCGIWeb/example.php';
if (!is_file($example)) {
    fwrite(STDERR, "fastcgi-soak: missing {$example}\n");
    exit(2);
}
$docRoot = dirname($example);
$params = [
    'REQUEST_METHOD' => 'GET',
    'SCRIPT_FILENAME' => $example,
    'SCRIPT_NAME' => '/example.php',
    'REQUEST_URI' => '/example.php',
    'DOCUMENT_ROOT' => $docRoot,
    'QUERY_STRING' => '',
    'CONTENT_LENGTH' => '0',
];

$handler = new RequestHandler($root.'/examples/009-FastCGIWeb');
$baselineAt = min(100, max(2, intdiv($requests, 10)));
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

echo "fastcgi-soak: requests={$requests} baseline_at={$baselineAt}\n";
echo "fastcgi-soak: rss_kb baseline={$rssAtBaseline} final={$rssFinal}\n";
echo "fastcgi-soak: emalloc baseline={$emallocAtBaseline} final={$emallocFinal}\n";

// Emalloc must stay at 0 after endRequest; allow tiny interpreter slop.
if ($emallocFinal > 4096) {
    fwrite(STDERR, "fastcgi-soak: FAIL emalloc final={$emallocFinal} (expected ≤4096 after endRequest)\n");
    exit(1);
}

if ($rssAtBaseline > 0 && $rssFinal > 0) {
    $limit = (int) ceil($rssAtBaseline * 1.25) + 1024; // 25% + 1 MiB slack for allocator noise
    if ($rssFinal > $limit) {
        fwrite(STDERR, "fastcgi-soak: FAIL rss final={$rssFinal} kb > limit={$limit} kb (baseline={$rssAtBaseline})\n");
        exit(1);
    }
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
