--TEST--
Language: Closure::getCurrent() — not advertised on PHP 8.2 reference profile (#14221, Zend/zend_closures.c)
--FILE--
<?php
echo method_exists(Closure::class, 'getCurrent') ? "fail\n" : "ok\n";
--EXPECT--
ok
