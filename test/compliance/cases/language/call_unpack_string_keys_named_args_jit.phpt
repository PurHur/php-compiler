--TEST--
Call-time argument unpacking with string keys binds named parameters (JIT) (#9391 / #9199)
--FILE--
<?php
function f($a, $b = 2) {
    echo $a, ',', $b, "\n";
}

$args = ['a' => 1];
f(...$args);
f(...['a' => 1]);
?>
--EXPECT--
1,2
1,2
