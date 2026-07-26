--TEST--
Language: final static property allowed on PROFILE=8.4 (#23403, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class A {
    public final static $x = 1;
}
echo A::$x, "\n";
--EXPECT--
1
