--TEST--
Language: enum backing type iterable rejected as Traversable|array (#26539, Zend/zend_compile.c)
--FILE--
<?php
enum E: iterable { case A; }
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Enum backing type must be int or string, Traversable|array given in %s on line %d
