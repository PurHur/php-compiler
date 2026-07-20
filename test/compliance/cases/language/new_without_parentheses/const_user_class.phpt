--TEST--
Language: class constant bare `new UserClass` rejected under PROFILE=8.4 (#21493, re-#19046, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class Foo {
    public function __construct(public int $n = 7) {}
}

class Holder {
    public const BAR = new Foo;
}

echo Holder::BAR->n, "\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: New expressions are not supported in this context
