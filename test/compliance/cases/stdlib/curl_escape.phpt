--TEST--
stdlib curl_escape() / curl_unescape() — RFC 3986 round-trip (#6351)
--FILE--
<?php
echo function_exists('curl_escape') ? "escape_exists\n" : "escape_missing\n";
echo function_exists('curl_unescape') ? "unescape_exists\n" : "unescape_missing\n";
var_export(curl_escape('foo@bar/baz'));
echo "\n";
var_export(curl_unescape('foo%40bar%2Fbaz'));
echo "\n";
var_export(curl_escape("caf\xe9"));
echo "\n";
var_export(curl_unescape('caf%C3%A9'));
echo "\n";
--EXPECT--
escape_exists
unescape_exists
'foo%40bar%2Fbaz'
'foo@bar/baz'
'caf%E9'
'café'
