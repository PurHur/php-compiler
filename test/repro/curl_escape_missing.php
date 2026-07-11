<?php

declare(strict_types=1);

echo function_exists('curl_escape') ? "escape_exists: yes\n" : "escape_exists: no\n";
echo function_exists('curl_unescape') ? "unescape_exists: yes\n" : "unescape_exists: no\n";

if (!function_exists('curl_escape') || !function_exists('curl_unescape')) {
    echo "ok: absent without ext/curl\n";
    exit(0);
}

var_export(curl_escape('foo@bar/baz'));
echo "\n";
var_export(curl_unescape('foo%40bar%2Fbaz'));
echo "\n";
var_export(curl_escape("caf\xe9"));
echo "\n";
var_export(curl_unescape('caf%C3%A9'));
echo "\n";
