--TEST--
Language: class true is compile-time fatal (#32206, Zend/zend_compile.c)
--FILE--
<?php
class true {}
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
%APHP Fatal error:  Cannot use 'true' as class name as it is reserved in %s on line %d
