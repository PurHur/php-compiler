--TEST--
Stdlib: array_multisort() preserves enum case objects (#5624, ext/standard/array.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }
$a = [E::B, E::A];
array_multisort($a);
var_dump($a);
--EXPECT--
array(2) {
  [0]=>
  enum(E::A)
  [1]=>
  enum(E::B)
}
