--TEST--
stdlib curl_escape() / curl_unescape() — RFC 3986 round-trip with CurlHandle (#6351, #20493)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
echo function_exists('curl_escape') ? "escape_exists\n" : "escape_missing\n";
echo function_exists('curl_unescape') ? "unescape_exists\n" : "unescape_missing\n";
$ch = curl_init();
var_export(curl_escape($ch, 'foo@bar/baz'));
echo "\n";
var_export(curl_unescape($ch, 'foo%40bar%2Fbaz'));
echo "\n";
var_export(curl_escape($ch, "caf\xe9"));
echo "\n";
var_export(curl_unescape($ch, 'caf%C3%A9'));
echo "\n";
--EXPECT--
escape_exists
unescape_exists
'foo%40bar%2Fbaz'
'foo@bar/baz'
'caf%E9'
'café'
