--TEST--
Language: (unset) cast — break reference chain (#3517)
--FILE--
<?php
$a = 1;
$b = &$a;
$b = 2;
echo $a, "\n";
$c = (unset) $b;
echo isset($b) ? 'set' : 'unset', "\n";
$b = 3;
echo $a, "\n";
echo $b, "\n";
$x = 5;
$y = (unset) $x;
echo $x, "\n";
--EXPECT--
2
unset
2
3
5
