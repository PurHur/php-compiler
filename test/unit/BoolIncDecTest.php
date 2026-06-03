<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM ++/-- on bool operands (issue #4727). */
final class BoolIncDecTest extends TestCase
{
    public function testIncrementTrueBecomesOne(): void
    {
        $v = new VMVariable();
        $v->bool(true);
        $v->applyIncrement();
        $this->assertSame(VMVariable::TYPE_INTEGER, $v->type);
        $this->assertSame(1, $v->toInt());
    }

    public function testIncrementFalseBecomesOne(): void
    {
        $v = new VMVariable();
        $v->bool(false);
        $v->applyIncrement();
        $this->assertSame(1, $v->toInt());
    }

    public function testDecrementTrueBecomesZero(): void
    {
        $v = new VMVariable();
        $v->bool(true);
        $v->applyDecrement();
        $this->assertSame(0, $v->toInt());
    }

    public function testDecrementFalseBecomesMinusOne(): void
    {
        $v = new VMVariable();
        $v->bool(false);
        $v->applyDecrement();
        $this->assertSame(-1, $v->toInt());
    }
}
