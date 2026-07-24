--TEST--
Language: child class const visibility must not narrow parent (#22929, Zend/zend_inheritance.c)
--FILE--
<?php
class A { public const X = 1; }
class B extends A { private const X = 2; }
echo "compiled\n";
--EXPECT_EXIT--
255
--EXPECTF--
%aAccess level to B::X must be public (as in class A)%a
