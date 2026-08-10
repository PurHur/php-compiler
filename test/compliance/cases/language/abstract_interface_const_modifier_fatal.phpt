--TEST--
Language: abstract modifier on interface constant compile fatal (#30011, Zend/zend_compile.c)
--FILE--
<?php
interface I {
    abstract public const X = 1;
}
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
PHP Fatal error:  Cannot use the abstract modifier on a class constant in %s on line %d
