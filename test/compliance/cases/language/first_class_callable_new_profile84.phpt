--TEST--
Language: new Class(...) first-class callable under PROFILE=8.4 (#23714, Zend/zend_compile.c)
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
--EXPECT--
7
