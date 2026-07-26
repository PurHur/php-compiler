--TEST--
clone Closure duplicates static table then diverges (issue #23489, Zend/zend_closures.c)
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

// Copy-at-clone: values snapshotted, then independent
$h = function ($x) {
    static $n = 0;
    return $x . (++$n);
};
echo $h("x"), "\n";
$i = clone $h;
echo $i("y"), "\n";
echo $h("z"), "\n";
echo $i("w"), "\n";

// Independently created closures stay distinct (#4872)
$a = function ($x) {
    static $n = 0;
    return $x . (++$n);
};
$b = function ($x) {
    static $n = 0;
    return $x . (++$n);
};
echo $a("p"), "\n";
echo $b("q"), "\n";
echo $a("r"), "\n";
--EXPECT--
a1
b1
c2
x1
y2
z2
w3
p1
q1
r2
