--TEST--
language WeakMap — offsetUnset under JIT (#4084)
--FILE--
<?php
class Box {}
$m = new WeakMap();
$o = new Box();
$m->offsetSet($o, 1);
unset($m[$o]);
var_export(isset($m[$o]));
echo "\n";
--EXPECT--
false
