--TEST--
stdlib mktime() null month uses current month (#14675, ext/standard/datetime.c)
--FILE--
<?php
$z = mktime(0, 0, 0, null, 1, 2020);
$ref = mktime(0, 0, 0, (int) date('n'), 1, 2020);
var_dump($z === $ref);
?>
--EXPECT--
bool(true)
