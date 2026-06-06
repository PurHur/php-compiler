<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM ++/-- on null operands (issue #4362). */
final class NullIncDecTest extends TestCase
{
    public function testIncrementNullThrowsTypeError(): void
    {
        $v = new VMVariable();
        $v->null();
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('Cannot increment null');
        $v->applyIncrement();
    }

    public function testDecrementNullThrowsTypeError(): void
    {
        $v = new VMVariable();
        $v->null();
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('Cannot decrement null');
        $v->applyDecrement();
    }
}
