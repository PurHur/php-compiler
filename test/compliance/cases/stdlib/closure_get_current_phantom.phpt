--TEST--
stdlib Closure::getCurrent() phantom withheld on 8.2 reference profile (#14221, Zend/zend_closures.c)
--FILE--
<?php
declare(strict_types=1);

echo method_exists(Closure::class, 'getCurrent') ? "exists\n" : "ok\n";
?>
--EXPECT--
ok
