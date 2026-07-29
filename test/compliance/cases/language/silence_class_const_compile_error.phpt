--TEST--
Language: silence (@) in class constant initializer must compile-error (#24905, Zend/zend_compile.c)
--FILE--
<?php
class A {
    public const X = @1;
}
echo A::X, "\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Constant expression contains invalid operations
