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

    public function testDecrementEmptyStringCoercesToIntMinusOne(): void
    {
        $this->assertSame(-1, $this->runIncDec(OpCode::TYPE_MINUS, '')->toInt());
    }

    public function testIncrementStringOperatorMatchesBuiltinCases(): void
    {
        $this->assertSame('b0', VmString::incrementStringOperator('a9'));
        $this->assertSame('aa', VmString::incrementStringOperator('z'));
        $this->assertSame('hellp', VmString::incrementStringOperator('hello'));
    }

    /** Zend increment_string lengthening uses last alphanumeric class (#21911). */
    public function testIncrementStringOperatorCarryCaseAndDigitOverflow(): void
    {
        $this->assertSame('AA', VmString::incrementStringOperator('Z'));
        $this->assertSame('10a', VmString::incrementStringOperator('9z'));
        $this->assertSame('B0', VmString::incrementStringOperator('A9'));
        $this->assertSame('AAA', VmString::incrementStringOperator('ZZ'));
        $this->assertSame('aaa', VmString::incrementStringOperator('zz'));
        $this->assertSame('1000', VmString::incrementStringOperator('999'));
    }

    /** Non-alnum bytes stop the chain without peri-mutate (zend_operators.c, #29658). */
    public function testIncrementStringOperatorStopsAtNonAlphanumeric(): void
    {
        $this->assertSame(' ', VmString::incrementStringOperator(' '));
        $this->assertSame('a-', VmString::incrementStringOperator('a-'));
        $this->assertSame('Z ', VmString::incrementStringOperator('Z '));
        $this->assertSame('-cd', VmString::incrementStringOperator('-cc'));
        $this->assertSame('1', VmString::incrementStringOperator(''));
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
