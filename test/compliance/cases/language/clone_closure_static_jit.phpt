--TEST--
clone Closure duplicates static table then diverges — JIT (issue #23489, Zend/zend_closures.c)
--FILE--
<?php
$f = function ($x) {
    static $n = 0;
    return $x . (++$n);
};
$g = clone $f;
echo $f("a"), "\n";
echo $g("b"), "\n";
echo $f("c"), "\n";
--EXPECT--
a1
b1
c2
