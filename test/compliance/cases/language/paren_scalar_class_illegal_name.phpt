--TEST--
Language: (1)::class / (1.5)::class — Illegal class name (#29625, Zend/zend_compile.c)
--FILE--
<?php
echo (1)::class;
--EXPECT_EXIT--
255
--EXPECTF--
PHP Fatal error:  Illegal class name in %s on line %d
