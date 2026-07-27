--TEST--
AOT: two closures/arrows in one unit compile and invoke (#23973)
--FILE--
<?php
$f = function ($a, $b) { return "$a+$b"; };
$x = 1;
echo $f($x + 1, $x + 2), PHP_EOL;
$g = fn ($a, $b) => "$a*$b";
echo $g($x + 3, $x + 4), PHP_EOL;
--EXPECT--
2+3
4*5
