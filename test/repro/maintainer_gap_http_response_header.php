<?php

declare(strict_types=1);

/**
 * Repro #11839 — $http_response_header after http:// file_get_contents().
 *
 * php-src: main/streams/streams.c — php_stream_response_header
 */

@file_get_contents('http://example.com/');

if (!isset($http_response_header) || !\is_array($http_response_header)) {
    echo "fail: http_response_header not set\n";
    exit(1);
}

$count = \count($http_response_header);
if ($count < 1 || !isset($http_response_header[0]) || !\is_string($http_response_header[0])) {
    echo "fail: http_response_header shape\n";
    exit(1);
}

if (!str_starts_with($http_response_header[0], 'HTTP/')) {
    echo "fail: status line\n";
    exit(1);
}

echo "ok count={$count}\n";
