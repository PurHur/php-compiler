--TEST--
stdlib usort() closure on enum case arrays preserves objects — JIT (#8867)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }
$a = [E::B, E::A];
usort($a, fn($x, $y) => $x <=> $y);
var_export($a);
echo "\n";
--EXPECT--
array (
  0 => \E::A,
  1 => \E::B,
)
