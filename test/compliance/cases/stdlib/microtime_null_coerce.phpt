--TEST--
stdlib microtime(null) coerces null to false like Z_PARAM_BOOL (issue #17025, ext/standard/microtime.c)
--FILE--
<?php
$s = microtime(null);
echo is_string($s) && strlen($s) > 10 ? "string\n" : "bad\n";
--EXPECT--
string
