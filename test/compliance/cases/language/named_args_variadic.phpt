--TEST--
Language: named arguments with variadic parameters — pack order parity (#4808, #4757)
--FILE--
<?php
function f(...$args) {
    return $args;
}
var_export(f(a: 1, b: 2));
echo "\n";
function g($x, ...$args) {
    return [$x, $args];
}
var_export(g(x: 1, a: 2, b: 3));
echo "\n";
var_export(g(1, b: 2));
echo "\n";
function h(...$a) {
    return $a;
}
var_export(h(a: 1, b: 2));
echo "\n";
--EXPECT--
array (
  'a' => 1,
  'b' => 2,
)
array (
  0 => 1,
  1 => array (
    'a' => 2,
    'b' => 3,
  ),
)
array (
  0 => 1,
  1 => array (
    'b' => 2,
  ),
)
array (
  'a' => 1,
  'b' => 2,
)
