--TEST--
AOT: closure use (&$var) captures by reference (#72, #2483)
--FILE--
<?php
$n = 10;
$f = function ($x) use (&$n) {
    return $x + $n;
};
echo $f(5), "\n";
$n = 99;
echo $f(5), "\n";
--EXPECT--
15
104
