--TEST--
Language: use Foo as self is compile-time fatal (#32254, Zend/zend_compile.c)
--FILE--
<?php
class Foo {}
use Foo as self;
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
%APHP Fatal error:  Cannot use Foo as self because 'self' is a special class name in %s on line %d
