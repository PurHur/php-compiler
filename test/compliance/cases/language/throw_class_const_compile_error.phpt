--TEST--
Language: throw in class constant initializer must compile-error (#6580, #10106, Zend/zend_ast.c)
--FILE--
<?php
class C {
    public const X = throw new Exception('x');
}
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Constant expression contains invalid operations
