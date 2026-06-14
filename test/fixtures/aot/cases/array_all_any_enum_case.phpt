--TEST--
AOT: array_all()/array_any() enum case predicate callbacks (#5722, ext/standard/array.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }

echo array_all([E::A, E::B], fn ($v) => $v === E::A || $v === E::B) ? "1" : "0", "\n";
echo array_any([E::A, E::B], fn ($v) => $v === E::A) ? "1" : "0", "\n";
echo array_any([E::A, E::B], fn ($v) => $v === E::B) ? "1" : "0", "\n";
--EXPECT--
1
1
1
--EXPECT_EXIT--
0
