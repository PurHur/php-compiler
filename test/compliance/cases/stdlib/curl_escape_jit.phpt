--TEST--
stdlib curl_escape() — JIT RFC 3986 round-trip with CurlHandle (#6351, #20493)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
$ch = curl_init();
var_export(curl_escape($ch, 'foo@bar/baz'));
echo "\n";
var_export(curl_unescape($ch, 'foo%40bar%2Fbaz'));
echo "\n";
--EXPECT--
'foo%40bar%2Fbaz'
'foo@bar/baz'
