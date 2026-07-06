--TEST--
stdlib Closure::getUsedVariables() phantom withheld on 8.2 reference profile (#16735, Zend/zend_closures.c)
--FILE--
<?php
declare(strict_types=1);

$c = function () {};
echo method_exists($c, 'getUsedVariables') ? "exists\n" : "ok\n";
?>
--EXPECT--
ok
