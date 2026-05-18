--TEST--
Default parameter values and optional arguments (JIT)
--FILE--
<?php
function f($a = 1, $b = 2) {
    return $a + $b;
}
echo f(), "\n";
echo f(10), "\n";
echo f(10, 20), "\n";
--EXPECT--
3
12
30
