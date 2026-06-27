--TEST--
stdlib array_replace()/array_replace_recursive() inline literals preserve enum cases (#8930, ext/standard/array.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }
var_export(array_replace([E::A], [1 => E::B]));
echo "\n";
var_export(array_replace_recursive([E::A], [1 => E::B]));
--EXPECT--
array (
  0 => \E::A,
  1 => \E::B,
)
array (
  0 => \E::A,
  1 => \E::B,
)
