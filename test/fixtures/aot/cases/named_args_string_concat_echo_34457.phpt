--TEST--
AOT: named string args + echo $a.$b must not segfault (#34457)
--FILE--
<?php
function s($a, $b) {
    echo $a.$b;
}
s(b:"y", a:"x");
echo "\n";
s(a:"x", b:"y");
echo "\n";
s("x", b:"Y");
echo "\n";
s("x", "y");
echo "\n";
function add($a, $b) {
    echo $a + $b;
}
add(b:2, a:1);
echo "\n";
function t($a, $b) {
    $t = $a.$b;
    echo $t;
}
t(b:"y", a:"x");
echo "\n";
--EXPECT--
xy
xy
xY
xy
3
xy
