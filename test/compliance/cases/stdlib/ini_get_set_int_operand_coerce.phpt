--TEST--
stdlib ini_get()/ini_set() — int option operand coerces on 8.2 reference profile (#17291, ext/standard/ini.c)
--FILE--
<?php
var_dump(ini_get(123));
var_dump(ini_set(456, 'x'));
?>
--EXPECT--
bool(false)
bool(false)
