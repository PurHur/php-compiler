--TEST--
Language: match in untyped class constant initializer must compile-error (#24904, Zend/zend_compile.c)
--FILE--
<?php
class A {
    public const X = match(1) { 1 => "one", default => "x" };
}
echo A::X, "\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Constant expression contains invalid operations
