--TEST--
language: closure in array element invoked indirectly (issue #72)
--FILE--
<?php
$f = function ($x) {
    return $x + 1;
};
$arr = [$f];
echo $arr[0](2), "\n";
--EXPECT--
3
