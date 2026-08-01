--TEST--
Language: enum backing type object rejected — must be int or string (#26539, Zend/zend_compile.c)
--FILE--
<?php
enum E: object { case A; }
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Enum backing type must be int or string, object given in %s on line %d
