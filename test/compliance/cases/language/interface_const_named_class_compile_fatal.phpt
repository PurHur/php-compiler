--TEST--
Language: interface const class is compile-time fatal (#32251, Zend/zend_compile.c)
--FILE--
<?php
interface I { const class = 1; }
echo I::class, "\n";
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
%APHP Fatal error:  A class constant must not be called 'class'; it is reserved for class name fetching in %s on line %d
