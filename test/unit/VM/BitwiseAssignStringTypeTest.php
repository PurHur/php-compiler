<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\VM;

use PHPCompiler\OpCode;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** Zend zend_operators.c compound bitwise assign with partial numeric strings (#5428). */
final class BitwiseAssignStringTypeTest extends TestCase
{
    public function testAndAssignCoercesToInt(): void
    {
        $left = new Variable(Variable::TYPE_INTEGER);
        $left->int(5);
        $right = new Variable(Variable::TYPE_STRING);
        $right->string('2x');
        $result = new Variable();
        $result->bitwiseOp(OpCode::TYPE_BITWISE_AND, $left, $right);

        self::assertSame(Variable::TYPE_INTEGER, $result->type);
        self::assertSame(0, $result->toInt());
    }

    public function testStringOperandsStayString(): void
    {
        $left = new Variable(Variable::TYPE_STRING);
        $left->string('5');
        $right = new Variable(Variable::TYPE_STRING);
        $right->string('2x');
        $result = new Variable();
        $result->bitwiseOp(OpCode::TYPE_BITWISE_AND, $left, $right);

        self::assertSame(Variable::TYPE_STRING, $result->type);
        self::assertSame('0', $result->toString());
    }
}
