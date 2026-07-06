--TEST--
stdlib microtime() — null $as_float coerces to false (#17025, ext/standard/microtime.c Z_PARAM_BOOL)
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
$s = microtime(null);
echo \is_string($s) && preg_match('/^\d+\.\d+ \d+$/', $s) ? "ok\n" : "fail\n";
--EXPECT--
ok
