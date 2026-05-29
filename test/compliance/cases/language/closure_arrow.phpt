--TEST--
language: arrow function desugars to closure (issue #142)
--FILE--
<?php
$f = fn ($x) => $x + 1;
echo $f(2), "\n";
$g = fn () => 99;
echo $g(), "\n";
--EXPECT--
3
99
