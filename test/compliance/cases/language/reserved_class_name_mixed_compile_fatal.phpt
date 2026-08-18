--TEST--
Language: class mixed is compile-time fatal (#32206, Zend/zend_compile.c)
--FILE--
<?php
class mixed {}
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
%APHP Fatal error:  Cannot use 'mixed' as class name as it is reserved in %s on line %d
