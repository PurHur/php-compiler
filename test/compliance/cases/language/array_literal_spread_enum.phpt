--TEST--
Language: array literal spread on enum case arrays preserves cases (#5569, zend_execute.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; case C = 3; }
$src = [E::A, E::B];
var_export([...$src]);
echo "\n";
$rest = [E::B, E::C];
var_export([E::A, ...$rest]);
echo "\n";
enum U { case X; case Y; }
$unit = [U::X, U::Y];
var_export([...$unit]);
echo "\n";
?>
--EXPECT--
array (
  0 => \E::A,
  1 => \E::B,
)
array (
  0 => \E::A,
  1 => \E::B,
  2 => \E::C,
)
array (
  0 => \U::X,
  1 => \U::Y,
)
