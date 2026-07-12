--TEST--
Language: static return type outside class — compile-time fatal (#17480, Zend/zend_compile.c)
--FILE--
<?php
declare(strict_types=1);

function f(): static
{
    return new static();
}
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Cannot use "static" when no class scope is active in %s on line %d
