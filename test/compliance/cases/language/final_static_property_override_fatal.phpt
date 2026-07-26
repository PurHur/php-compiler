--TEST--
Language: cannot override final static property (#23403, Zend/zend_inheritance.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class A {
    public final static $x = 1;
}
class B extends A {
    public static $x = 2;
}
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Cannot override final property A::$x
