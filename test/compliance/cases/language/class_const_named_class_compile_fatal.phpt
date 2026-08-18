--TEST--
Language: class const class is compile-time fatal (#32251, Zend/zend_compile.c)
--FILE--
<?php
class Foo { const class = 1; }
echo Foo::class, "\n";
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
%APHP Fatal error:  A class constant must not be called 'class'; it is reserved for class name fetching in %s on line %d
