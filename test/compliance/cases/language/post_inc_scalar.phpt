--TEST--
Language: scalar post/pre inc/dec return values (#4926, zend_operators.c)
--FILE--
<?php
$x = 1;
echo $x++, "\n";
var_export($x);
echo "\n";

$y = 1;
echo ++$y, "\n";
var_export($y);
echo "\n";

$z = 2;
echo $z--, "\n";
var_export($z);
echo "\n";

$w = 2;
echo --$w, "\n";
var_export($w);
echo "\n";
--EXPECT--
1
2
2
2
2
1
1
1
