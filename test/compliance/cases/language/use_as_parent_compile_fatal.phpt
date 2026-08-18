--TEST--
Language: use Foo as parent is compile-time fatal (#32254, Zend/zend_compile.c)
--FILE--
<?php
class Foo {}
use Foo as parent;
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
%APHP Fatal error:  Cannot use Foo as parent because 'parent' is a special class name in %s on line %d
