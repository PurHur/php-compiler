--TEST--
stdlib func_get_args() inside function with zero explicit args (#19617)
--FILE--
<?php
function f($a = null) {
    var_export(func_get_args());
    echo PHP_EOL;
}
f();
f(1, 2);

$inner = function () {
    var_export(func_get_args());
    echo PHP_EOL;
};
$inner();
$inner(9);
--EXPECT--
array (
)
array (
  0 => 1,
  1 => 2,
)
array (
)
array (
  0 => 9,
)
