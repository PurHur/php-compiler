--TEST--
Language: `in` operator is a Parse error — php-src has no T_IN (#31158, Zend/zend_language_parser.y)
--FILE--
<?php
echo 1 in [1,2] ? "yes\n" : "no\n";
--EXPECT_EXIT--
255
--EXPECTF--
PHP Parse error:  syntax error, unexpected identifier "in", expecting "," or ";" in %s on line %d
