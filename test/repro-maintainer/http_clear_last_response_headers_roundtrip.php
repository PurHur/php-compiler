<?php

declare(strict_types=1);

if (!function_exists('http_get_last_response_headers')) {
    fwrite(STDERR, "skip: PHP 8.4 profile required\n");
    exit(77);
}

if (function_exists('http_clear_last_response_headers')) {
    http_clear_last_response_headers();
}

$ctx = stream_context_create(['http' => ['method' => 'GET', 'ignore_errors' => true]]);
@file_get_contents('https://example.com', false, $ctx);
$before = http_get_last_response_headers();
echo 'before_count=', is_array($before) ? count($before) : -1, "\n";
if (is_array($before) && $before !== []) {
    echo 'status=', $before[0] ?? '', "\n";
}
http_clear_last_response_headers();
$after = http_get_last_response_headers();
echo 'after_empty=', null === $after ? 'yes' : 'no', "\n";

if (!is_array($before) || count($before) < 1) {
    fwrite(STDERR, "fail: expected HTTP response headers after fetch\n");
    exit(1);
}
if (!isset($before[0]) || !str_starts_with((string) $before[0], 'HTTP/')) {
    fwrite(STDERR, "fail: first header line must be HTTP status\n");
    exit(1);
}
if (null !== $after) {
    fwrite(STDERR, "fail: buffer not cleared to null\n");
    exit(1);
}

echo "ok\n";
