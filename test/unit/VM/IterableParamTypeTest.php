<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\VM;

use PHPCompiler\Runtime;
use PHPCompiler\VM\BuiltinClasses;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\IterableCheck;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** @covers \PHPCompiler\VM\IterableCheck */
final class IterableParamTypeTest extends TestCase
{
    public function testRejectsNonIterableScalars(): void
    {
        $ctx = new Context(new Runtime());
        BuiltinClasses::register($ctx);
        $int = new Variable(Variable::TYPE_INTEGER);
        $int->int(1);
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('Traversable|array');
        $this->expectExceptionMessage('int given');
        IterableCheck::assertParameter($int, $ctx);
    }

    public function testAcceptsArray(): void
    {
        $ctx = new Context(new Runtime());
        BuiltinClasses::register($ctx);
        $arr = new Variable(Variable::TYPE_ARRAY);
        $arr->array(new \PHPCompiler\VM\HashTable());
        IterableCheck::assertParameter($arr, $ctx);
        $this->addToAssertionCount(1);
    }
}
