--TEST--
By-reference parameter mutates caller variable (JIT, issue #3161)
--FILE--
<?php
function inc(&$n) {
    $n++;
}
$x = 1;
inc($x);
echo $x, "\n";
function scale(&$n, $factor) {
    $n *= $factor;
}
$y = 3;
scale($y, 4);
echo $y, "\n";
--EXPECT--
2
12
