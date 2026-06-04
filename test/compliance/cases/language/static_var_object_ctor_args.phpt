--TEST--
Function-local static object initializer with constructor args (issue #5445)
--FILE--
<?php
declare(strict_types=1);

class Box
{
    public function __construct(public int $v)
    {
    }
}

function demo(): void
{
    static $b = new Box(42);
    echo $b->v, "\n";
}

demo();
demo();
--EXPECT--
42
42
