--TEST--
Weak mode coerces numeric string to int parameter (issue #156)
--FILE--
<?php
function f(int $x) {
    return $x;
}
echo f('1'), "\n";
echo f(2), "\n";
--EXPECT--
1
2
