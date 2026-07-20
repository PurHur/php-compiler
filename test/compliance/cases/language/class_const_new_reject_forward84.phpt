--TEST--
Language: class constant `new` rejected under PROFILE=8.4 (#21493, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php

declare(strict_types=1);

class A {
    public const X = new stdClass;
}

echo gettype(A::X);
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: New expressions are not supported in this context
