--TEST--
stdlib settype() to object JIT — int scalar (#4254)
--JIT--
--FILE--
<?php
$x = 42;
settype($x, 'object');
var_dump($x);
--EXPECTF--
object(stdClass)#%d (1) {
  ["scalar"]=>
  int(42)
}
