--TEST--
stdlib usort() bool callback return — php_usort_compare parity (#13029, ext/standard/array.c)
--FILE--
<?php
$a = [3, 1, 2];
usort($a, static fn (int $x, int $y): bool => ($x <=> $y) ? true : false);
var_export($a);
--EXPECT--
array (
  0 => 1,
  1 => 2,
  2 => 3,
)
