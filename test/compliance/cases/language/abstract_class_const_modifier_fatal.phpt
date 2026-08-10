--TEST--
Language: abstract modifier on class constant compile fatal (#30011, Zend/zend_compile.c)
--FILE--
<?php
abstract class A {
    abstract public const X;
}
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
PHP Fatal error:  Cannot use the abstract modifier on a class constant in %s on line %d
