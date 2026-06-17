--TEST--
Call-time argument unpacking with string keys binds named parameters (issue #9199)
--FILE--
<?php
function f($a, $b = 2) {
    echo $a, ',', $b, "\n";
}

$args = ['a' => 1];
f(...$args);

--EXPECT--
1,2

