--TEST--
stdlib in_array()/array_search() — enum needle matches enum haystack (#8796, ext/standard/array.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }

var_export(in_array(E::A, [E::A, E::B]));
echo "\n";
var_export(in_array(E::A, [E::A, E::B], true));
echo "\n";
var_export(array_search(E::A, [E::A, E::B]));
echo "\n";
var_export(in_array(1, [E::A], false));
echo "\n";
--EXPECT--
true
true
0
false
