<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\VM;

use PHPCompiler\OpCode;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** Zend zend_operators.c unary ~ (#4998). */
final class BitwiseNotUnaryTest extends TestCase
{
    public function testIntegerOperand(): void
    {
        $src = new Variable(Variable::TYPE_INTEGER);
        $src->int(5);
        $result = new Variable();
        $result->unaryOp(OpCode::TYPE_BITWISE_NOT, $src);

        self::assertSame(Variable::TYPE_INTEGER, $result->type);
        self::assertSame(-6, $result->toInt());
    }

    public function testNumericStringOperandIsStringResult(): void
    {
        $src = new Variable(Variable::TYPE_STRING);
        $src->string('5');
        $result = new Variable();
        $result->unaryOp(OpCode::TYPE_BITWISE_NOT, $src);

        self::assertSame(Variable::TYPE_STRING, $result->type);
        self::assertSame('ca', bin2hex($result->toString()));
    }

    public function testBoolOperandThrowsTypeError(): void
    {
        $src = new Variable(Variable::TYPE_BOOLEAN);
        $src->bool(true);
        $result = new Variable();

        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('Cannot perform bitwise not on bool');
        $result->unaryOp(OpCode::TYPE_BITWISE_NOT, $src);
    }

    public function testFloatOperandThrowsTypeError(): void
    {
        $src = new Variable(Variable::TYPE_FLOAT);
        $src->float(1.5);
        $result = new Variable();

        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('Unsupported operand types: float');
        $result->unaryOp(OpCode::TYPE_BITWISE_NOT, $src);
    }
}
