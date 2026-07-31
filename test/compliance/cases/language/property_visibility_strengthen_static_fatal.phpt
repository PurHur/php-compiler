--TEST--
Language: child static property visibility must not strengthen parent (#25661, Zend/zend_inheritance.c)
--FILE--
<?php
class A { public static $x = 1; }
class B extends A { protected static $x = 1; }
echo "LOADED\n";
--EXPECT_EXIT--
255
--EXPECTF--
%aAccess level to B::$x must be public (as in class A)%a
