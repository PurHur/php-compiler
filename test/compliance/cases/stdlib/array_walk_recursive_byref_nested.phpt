--TEST--
stdlib array_walk_recursive() — closure by-ref mutates nested leaves (#5215)
--FILE--
<?php
$b = ['x' => [1, 2]];
array_walk_recursive($b, function (&$v) {
    $v++;
});
var_export($b);
echo "\n";
$c = [0 => [1 => [2 => 3]]];
array_walk_recursive($c, function (&$v) {
    $v = 9;
});
var_export($c);
echo "\n";
--EXPECT--
array (
  'x' => array (
    0 => 2,
    1 => 3,
  ),
)
array (
  0 => array (
    1 => array (
      2 => 9,
    ),
  ),
)
