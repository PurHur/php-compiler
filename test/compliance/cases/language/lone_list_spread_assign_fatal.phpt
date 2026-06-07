--TEST--
Language: lone list spread assignment — compile-time fatal (#6936, zend_compile.c)
--FILE--
<?php
[...$a] = [1, 2, 3];
var_dump($a);
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Spread operator is not supported in assignments in %s on line %d
