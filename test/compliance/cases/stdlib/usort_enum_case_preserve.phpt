--TEST--
stdlib usort() closure on enum case arrays preserves objects (#8867, ext/standard/array.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }
$a = [E::B, E::A];
usort($a, fn($x, $y) => $x <=> $y);
var_export($a);
echo "\n";
enum U { case X; case Y; }
$b = [U::Y, U::X];
usort($b, fn($x, $y) => strcmp($x->name, $y->name));
var_export($b);
echo "\n";
--EXPECT--
array (
  0 => \E::A,
  1 => \E::B,
)
array (
  0 => \U::X,
  1 => \U::Y,
)
