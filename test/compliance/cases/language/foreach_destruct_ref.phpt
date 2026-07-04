--TEST--
foreach list()/[] destructuring by-reference writes through to haystack (#16213, Zend/zend_execute.c)
--FILE--
<?php
$a = [[1]];
foreach ($a as list(&$v)) {
    $v = 2;
}
echo $a[0][0], "\n";

$b = [[1, 2]];
foreach ($b as [$x, &$y]) {
    $y = 9;
}
echo $b[0][1], "\n";
--EXPECT--
2
9
