--TEST--
stdlib func_get_args() zero-arg call JIT (#19617)
--FILE--
<?php
function f($a = null) {
    var_export(func_get_args());
    echo PHP_EOL;
}
f();
f(1, 2);
--EXPECT--
array (
)
array (
  0 => 1,
  1 => 2,
)
