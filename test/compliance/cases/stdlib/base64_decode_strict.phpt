--TEST--
stdlib base64_decode() — $strict rejects invalid input (issue #4184, ext/standard/base64.c)
--FILE--
<?php
var_export(base64_decode('YQ'));
echo "\n";
var_export(base64_decode('YQ', true));
echo "\n";
var_export(base64_decode('YQ==', true));
echo "\n";
var_export(base64_decode('YQ=a', true));
echo "\n";
var_export(base64_decode('YQ!!', true));
echo "\n";
var_export(base64_decode("Zm9v\n", true));
echo "\n";
--EXPECT--
'a'
'a'
'a'
false
false
'foo'
