--TEST--
language: closure use (&$var) mutates enclosing variable (issue #72)
--FILE--
<?php
$x = 1;
$f = function () use (&$x) {
    $x = 2;
};
$f();
echo $x, "\n";
--EXPECT--
2
