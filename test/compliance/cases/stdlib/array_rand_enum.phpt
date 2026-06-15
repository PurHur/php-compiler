--TEST--
stdlib array_rand() on enum case arrays preserves enum objects (#5598, ext/standard/array.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; case C = 3; }
$arr = [E::A, E::B, E::C];
echo ($arr[array_rand($arr)] instanceof E) ? "ok\n" : "bad\n";
--EXPECT--
ok
