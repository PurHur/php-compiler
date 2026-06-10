--TEST--
stdlib array_search() strict — enum needle must not match backing int haystack (#5668, ext/standard/array.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }

var_export(array_search(1, [E::A, E::B], true));
echo "\n";
var_export(array_search(E::B, [E::A, E::B], true));
echo "\n";
var_export(array_search(E::A, [E::A], true));
echo "\n";
--EXPECT--
false
1
0
