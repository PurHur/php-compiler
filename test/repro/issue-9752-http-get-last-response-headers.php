<?php
// Repro for #9752 — http_get_last_response_headers() after HTTP wrapper fetch.
if (function_exists('http_clear_last_response_headers')) {
    http_clear_last_response_headers();
}
$ctx = stream_context_create(['http' => ['method' => 'GET', 'ignore_errors' => true]]);
@file_get_contents('https://example.com', false, $ctx);
$h = http_get_last_response_headers();
var_export(is_array($h));
echo "\n";
if (is_array($h)) {
    echo count($h), "\n";
    echo ($h[0] ?? ''), "\n";
}
