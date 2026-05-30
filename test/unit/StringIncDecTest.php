<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM ++/-- on string operands (issue #3469). */
final class StringIncDecTest extends TestCase
{
    public function testIncrementNumericString(): void
    {
        $this->assertSame(10, $this->runIncDec(OpCode::TYPE_PLUS, '9')->toInt());
    }

    public function testIncrementAlphanumeric(): void
    {
        $this->assertSame('b0', $this->runIncDec(OpCode::TYPE_PLUS, 'a9')->toString());
    }

    public function testIncrementMixedUpper(): void
    {
        $this->assertSame('AB0', $this->runIncDec(OpCode::TYPE_PLUS, 'AA9')->toString());
    }

    public function testIncrementCarryLetter(): void
    {
        $this->assertSame('Ba', $this->runIncDec(OpCode::TYPE_PLUS, 'Az')->toString());
    }

    public function testDecrementAlphanumericIsNoOp(): void
    {
        $this->assertSame('a9', $this->runIncDec(OpCode::TYPE_MINUS, 'a9')->toString());
    }

    public function testDecrementNumericString(): void
    {
        $this->assertSame(9, $this->runIncDec(OpCode::TYPE_MINUS, '10')->toInt());
    }

    public function testIncrementStringOperatorMatchesBuiltinCases(): void
    {
        $this->assertSame('b0', VmString::incrementStringOperator('a9'));
        $this->assertSame('aa', VmString::incrementStringOperator('z'));
        $this->assertSame('hellp', VmString::incrementStringOperator('hello'));
    }

    private function runIncDec(int $opCode, string $value): VMVariable
    {
        $left = new VMVariable();
        $left->string($value);
        $one = new VMVariable();
        $one->int(1);
        $result = new VMVariable();
        $result->incDecOp($opCode, $left, $one);

        return $result;
    }
}
