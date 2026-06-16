--TEST--
stdlib array_search() strict JIT — int needle finds literal int not enum case (#8886)
--JIT--
--FILE--
<?php
enum E: int { case A = 1; }

var_export(array_search(1, [E::A, 1], true));
echo "\n";
var_export(array_search(1, [1, E::A], true));
echo "\n";
--EXPECT--
1
0
