--TEST--
Implicit nullable typed parameter: int $x = null accepts omitted call (#4449)
--FILE--
<?php
function f(int $x = null) {
    echo null === $x ? "null\n" : "set\n";
}
f();
--EXPECT--
null
