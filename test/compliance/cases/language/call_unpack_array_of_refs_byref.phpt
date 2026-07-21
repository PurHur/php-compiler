--TEST--
Language: unpack array-of-refs into by-ref param writes back (#21913, Zend/zend_execute.c ZEND_SEND_UNPACK)
--FILE--
<?php
function f(&$a) {
    $a = 5;
}
$x = 1;
$args = [&$x];
f(...$args);
echo "var x=$x\n";

$y = 1;
f(...[&$y]);
echo "inline y=$y\n";

$z = 1;
$vals = [$z];
f(...$vals);
echo "value z=$z vals0={$vals[0]}\n";

function g(&$a, &$b) {
    $a = 7;
    $b = 8;
}
$p = 1;
$q = 2;
$multi = [&$p, &$q];
g(...$multi);
echo "multi p=$p q=$q\n";

function named(&$a) {
    $a = 9;
}
$n = 1;
named(...['a' => &$n]);
echo "named n=$n\n";
--EXPECT--
var x=5
inline y=5
value z=1 vals0=5
multi p=7 q=8
named n=9
