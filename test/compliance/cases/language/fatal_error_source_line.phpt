--TEST--
Language: uncaught VM fatals cite user script file and line (#13201, Zend/zend_exceptions.c)
--FILE--
<?php
undefined();
--EXPECT_EXIT--
255
--EXPECTF--
PHP Fatal error:  Uncaught Error: Call to undefined function undefined() in -:2%A
