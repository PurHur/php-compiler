--TEST--
stdlib array_all()/array_any() — enum case instanceof in predicate callback (#9471, ext/standard/array.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }

var_export(array_all([E::A, E::B], fn ($v) => $v instanceof E));
echo "\n";
var_export(array_any([E::A, E::B], fn ($v) => $v instanceof E));
echo "\n";
?>
--EXPECT--
true
true
