--TEST--
language: nested arrow functions capture outer parameter (issues #4944, #4952)
--FILE--
<?php
$f = fn (int $n) => fn () => $n * 2;
echo $f(3)(), "\n";
$x = 5;
$g = fn () => $x + 1;
echo $g(), "\n";
--EXPECT--
6
6
