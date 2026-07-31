--TEST--
Language: new Class(...) first-class callable rejected under PROFILE=8.4 (#26188, re-#10130, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

class C
{
    public function __construct(public int $x)
    {
    }
}

$f = new C(...);
echo $f(7)->x, "\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Cannot create Closure for new expression
