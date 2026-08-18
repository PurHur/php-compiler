--TEST--
Language: trait never is compile-time fatal (#32206, Zend/zend_compile.c)
--FILE--
<?php
trait never {}
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
%APHP Fatal error:  Cannot use 'never' as class name as it is reserved in %s on line %d
