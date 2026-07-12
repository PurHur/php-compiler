--TEST--
Language: (expr)::class on invalid constant operand — compile-time Fatal error (#17949, Zend/zend_compile.c)
--FILE--
<?php
declare(strict_types=1);

try {
    echo (1 + 2)::class;
} catch (Error $e) {
    echo "caught\n";
}
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Cannot use "::class" on value of type int in %s on line %d
