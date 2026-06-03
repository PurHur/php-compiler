<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\VM;

use PHPCompiler\OpCode;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** Zend zend_operators.c compound assign with invalid numeric strings (#4892). */
final class CompoundAssignNonNumericTest extends TestCase
{
    public function testPlusAssignCoercesLeadingNumericPrefix(): void
    {
        $left = new Variable(Variable::TYPE_INTEGER);
        $left->int(1);
        $right = new Variable(Variable::TYPE_STRING);
        $right->string('2abc');
        $result = new Variable();
        $result->numericOp(OpCode::TYPE_PLUS, $left, $right);

        self::assertSame(Variable::TYPE_INTEGER, $result->type);
        self::assertSame(3, $result->toInt());
    }

    public function testFullyNonNumericStringThrowsTypeError(): void
    {
        $left = new Variable(Variable::TYPE_INTEGER);
        $left->int(1);
        $right = new Variable(Variable::TYPE_STRING);
        $right->string('abc');

        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('Unsupported operand types: string');

        $result = new Variable();
        $result->numericOp(OpCode::TYPE_PLUS, $left, $right);
    }
}
