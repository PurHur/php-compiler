--TEST--
named arguments reorder user function parameters (JIT)
--FILE--
<?php
function f(int $a, int $b): int {
    return $a + $b;
}
echo f(b: 2, a: 3);
--EXPECT--
5
