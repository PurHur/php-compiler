--TEST--
Call-time argument unpacking with string keys binds named parameters (issue #9698 / #9199)
--FILE--
<?php
function f($a, $b = 2) {
    echo $a, ',', $b, "\n";
}

$args = ['a' => 1];
f(...$args);

function g($a, $b, $c = 3) {
    echo $a, ',', $b, ',', $c, "\n";
}
g(...[0 => 10, 1 => 20]);
--EXPECT--
1,2
10,20,3
