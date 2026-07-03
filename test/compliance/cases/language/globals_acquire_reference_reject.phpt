--TEST--
Language: $ref = &$GLOBALS must compile-time fatal (#15627, Zend/zend_compile.c)
--FILE--
<?php
$ref = &$GLOBALS;
echo "fail: acquired GLOBALS reference\n";
--EXPECT_EXIT--
255
