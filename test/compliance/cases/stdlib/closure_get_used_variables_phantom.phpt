--TEST--
stdlib Closure::getUsedVariables() phantom withheld on 8.2 reference profile (#16735, Zend/zend_closures.c)
--FILE--
<?php
declare(strict_types=1);

$x = 1;
$y = 'two';
$c = function () use ($x, &$y) {
    return $x . $y;
};
echo method_exists($c, 'getUsedVariables') ? "exists\n" : "ok\n";
?>
--EXPECT--
ok
