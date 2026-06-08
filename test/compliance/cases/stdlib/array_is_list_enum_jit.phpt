--TEST--
JIT: array_is_list() on enum case lists is true (#6154, ext/standard/array.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }
$arr = [E::A, E::B];
echo array_is_list($arr) ? "list\n" : "not-list\n";
echo array_is_list(['a' => E::A]) ? "bad\n" : "assoc\n";
--EXPECT--
list
assoc
