--TEST--
stdlib microtime(null) JIT coerces null to false (issue #17025)
--FILE--
<?php
$s = microtime(null);
echo is_string($s) && strlen($s) > 10 ? "string\n" : "bad\n";
--EXPECT--
string
