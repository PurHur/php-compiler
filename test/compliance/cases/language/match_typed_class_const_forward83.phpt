--TEST--
Language: match in class constant initializer is not a const-expr (#24904, re-#9987, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.3
--FILE--
<?php
class C {
    public const int X = match (2) { 1 => 10, 2 => 20, default => 0 };
}
var_export(C::X);
echo "\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Constant expression contains invalid operations
