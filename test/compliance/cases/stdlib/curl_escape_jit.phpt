--TEST--
stdlib curl_escape() — JIT RFC 3986 round-trip (#6351)
--FILE--
<?php
var_export(curl_escape('foo@bar/baz'));
echo "\n";
var_export(curl_unescape('foo%40bar%2Fbaz'));
echo "\n";
--EXPECT--
'foo%40bar%2Fbaz'
'foo@bar/baz'
