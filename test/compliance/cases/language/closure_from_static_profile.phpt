--TEST--
language Closure::fromStatic() withheld on 8.2 reference profile (#16666, Zend/zend_closures.c)
--FILE--
<?php
declare(strict_types=1);

echo method_exists(Closure::class, 'fromStatic') ? "exists\n" : "ok\n";
?>
--EXPECT--
ok
