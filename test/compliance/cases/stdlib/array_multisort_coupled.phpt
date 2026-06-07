--TEST--
stdlib array_multisort() coupled two-array form — by-ref without refcount abort (#7246)
--FILE--
<?php
$a = [3, 1, 2];
$b = ['c', 'a', 'b'];
array_multisort($a, $b);
var_export([$a, $b]);
echo "\n";
--EXPECT--
array (
  0 => array (
    0 => 1,
    1 => 2,
    2 => 3,
  ),
  1 => array (
    0 => 'a',
    1 => 'b',
    2 => 'c',
  ),
)
