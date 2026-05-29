--TEST--
language: anonymous closure without use() (issue #72)
--FILE--
<?php
$f = function ($x) {
    return $x + 1;
};
echo $f(2), "\n";
$g = function () {
    return 40 + 2;
};
echo $g(), "\n";
--EXPECT--
3
42
