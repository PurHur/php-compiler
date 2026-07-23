--TEST--
By-ref param `$a =& $static` rebinds local CV only — caller unchanged; write-through still updates caller (#22546, Zend/zend_execute.c ASSIGN_REF)
--FILE--
<?php
function f(&$a = null) {
    static $x = 0;
    $x++;
    if ($a === null) {
        $a =& $x;
    }
    return $x;
}
$r = null;
f($r);
echo var_export($r, true), "\n";
f($r);
echo var_export($r, true), "\n";

function g(&$a) {
    $a = 99;
}
$s = 1;
g($s);
echo var_export($s, true), "\n";

function h(&$a) {
    $x = 5;
    $a =& $x;
    $a = 7;
    return $x;
}
$t = 1;
echo h($t), ",", var_export($t, true), "\n";

function loc() {
    $c = 1;
    $b =& $c;
    $a = 2;
    $b =& $a;
    echo $c, ",", $b, "\n";
    $b = 99;
    echo $c, ",", $a, "\n";
}
loc();

// `global $g` then `$g =& $x` rebinds the function CV only (outer symbol unchanged).
function glob_rebind() {
    global $g;
    $x = 5;
    $g =& $x;
    echo "inner=", $g, "\n";
}
$g = 1;
glob_rebind();
echo "outer=", $g, "\n";

// Main-script `$a =& $b` must keep `$GLOBALS['a']` linked.
$ga = 10;
$gb = 20;
$ga =& $gb;
echo "GLOBALS=", $GLOBALS['ga'], ",", $ga, ",", $gb, "\n";
--EXPECT--
NULL
NULL
99
7,1
1,2
1,99
inner=5
outer=1
GLOBALS=20,20,20
