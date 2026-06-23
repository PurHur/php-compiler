--TEST--
AOT microtime() named as_float flag (issue #10644)
--FILE--
<?php
$f = microtime(as_float: true);
echo is_float($f) ? "float\n" : "bad\n";
$s = microtime(as_float: false);
echo strlen($s) > 10 ? "parts\n" : "bad\n";
--EXPECT--
float
parts
