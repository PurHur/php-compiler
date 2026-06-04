--TEST--
Default parameter values with array spread (PHP 8.1+, #5347)
--FILE--
<?php
function f(array $a = [1, ...[2, 3]]) {
    return $a;
}
function g(array $a = [...[1, 2], 3]) {
    return $a;
}
function h(array $a = [1, ...[2, 3]], $b = 99) {
    return [$a, $b];
}
var_export(f());
echo "\n";
var_export(g());
echo "\n";
var_export(h());
echo "\n";
var_export(h([0], 1));
echo "\n";
--EXPECT--
array (
  0 => 1,
  1 => 2,
  2 => 3,
)
array (
  0 => 1,
  1 => 2,
  2 => 3,
)
array (
  0 => array (
    0 => 1,
    1 => 2,
    2 => 3,
  ),
  1 => 99,
)
array (
  0 => array (
    0 => 0,
  ),
  1 => 1,
)
