--TEST--
AOT: Deprecated builtin attribute class on PHP_COMPILER_PROFILE=8.4 (#17318, Zend/zend_attributes.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo class_exists('Deprecated', false) ? "ok\n" : "fail\n";
--EXPECT--
ok
