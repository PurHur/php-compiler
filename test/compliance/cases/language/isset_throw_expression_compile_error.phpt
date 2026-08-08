--TEST--
Language: isset() on throw expression must compile-error (#29086, Zend/zend_compile.c)
--FILE--
<?php
isset(throw new Exception('x'));
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Cannot use isset() on the result of an expression (you can use "null !== expression" instead)
