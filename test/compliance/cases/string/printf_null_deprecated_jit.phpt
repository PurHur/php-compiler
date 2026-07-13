--TEST--
stdlib printf(null) — E_DEPRECATED JIT via sprintf lowering (#18764, ext/standard/formatted_io.c)
--FILE--
<?php
error_reporting(E_ALL);
$result = sprintf(null);
echo $result === '' ? "ok\n" : "bad\n";
?>
--EXPECTF--
PHP Deprecated:  sprintf(): Passing null to parameter #1 ($format) of type string is deprecated in %s on line %d
ok
