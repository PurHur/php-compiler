<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM ++/-- on bool operands (issue #7058, re-#4727). */
final class BoolIncDecTest extends TestCase
{
    public function testIncrementTrueIsNoOp(): void
    {
        $v = new VMVariable();
        $v->bool(true);
        $v->applyIncrement();
        $this->assertSame(VMVariable::TYPE_BOOLEAN, $v->type);
        $this->assertTrue($v->toBool());
    }

    public function testIncrementFalseIsNoOp(): void
    {
        $v = new VMVariable();
        $v->bool(false);
        $v->applyIncrement();
        $this->assertSame(VMVariable::TYPE_BOOLEAN, $v->type);
        $this->assertFalse($v->toBool());
    }

    public function testDecrementTrueIsNoOp(): void
    {
        $v = new VMVariable();
        $v->bool(true);
        $v->applyDecrement();
        $this->assertSame(VMVariable::TYPE_BOOLEAN, $v->type);
        $this->assertTrue($v->toBool());
    }

    public function testDecrementFalseIsNoOp(): void
    {
        $v = new VMVariable();
        $v->bool(false);
        $v->applyDecrement();
        $this->assertSame(VMVariable::TYPE_BOOLEAN, $v->type);
        $this->assertFalse($v->toBool());
    }
}
