<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM ++/-- on null operands (issue #7435, zend_operators.c). */
final class NullIncDecTest extends TestCase
{
    public function testIncrementNullCoercesToOne(): void
    {
        $v = new VMVariable();
        $v->null();
        $v->applyIncrement();
        $this->assertSame(VMVariable::TYPE_INTEGER, $v->type);
        $this->assertSame(1, $v->toInt());
    }

    public function testDecrementNullIsNoOp(): void
    {
        $v = new VMVariable();
        $v->null();
        $v->applyDecrement();
        $this->assertSame(VMVariable::TYPE_NULL, $v->type);
    }

    public function testIncrementUndefinedCoercesToOne(): void
    {
        $v = new VMVariable(VMVariable::TYPE_UNDEFINED);
        $v->applyIncrement();
        $this->assertSame(VMVariable::TYPE_INTEGER, $v->type);
        $this->assertSame(1, $v->toInt());
    }
}
