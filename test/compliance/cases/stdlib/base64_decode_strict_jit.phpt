--TEST--
stdlib base64_decode() JIT — $strict rejects invalid input (issue #4184)
--FILE--
<?php
var_export(base64_decode('YQ', true));
echo "\n";
var_export(base64_decode('YQ=a', true));
echo "\n";
var_export(base64_decode('YQ!!', true));
echo "\n";
--EXPECT--
'a'
false
false
