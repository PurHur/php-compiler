--TEST--
Function static `$s = &$param` rebinds CV only — static HT keeps prior value across calls (#21993, Zend/zend_execute.c BIND_STATIC)
--FILE--
<?php
function f(&$x = null) {
    static $s = 0;
    if ($x !== null) {
        $s = &$x;
    }
    return ++$s;
}
$a = 10;
echo f($a), ",", f(), ",", f(), ",", $a, "\n";

function g(&$x = null) {
    static $s = 5;
    if ($x !== null) {
        $s = &$x;
    }
    return ++$s;
}
$b = 100;
echo g($b), ",", g(), ",", $b, "\n";

function loc() {
    static $s = 0;
    $local = 42;
    $s = &$local;
    return ++$s;
}
echo loc(), ",", loc(), "\n";
--EXPECT--
11,1,2,11
101,6,101
43,43
