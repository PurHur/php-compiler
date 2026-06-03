<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\VM;

use PHPCompiler\OpCode;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** Zend shift_left/right_function float operands truncate to int (#5270). */
final class ShiftFloatTypeErrorTest extends TestCase
{
    public function testIntShiftFloatTruncates(): void
    {
        $left = new Variable(Variable::TYPE_INTEGER);
        $left->int(1);
        $right = new Variable(Variable::TYPE_FLOAT);
        $right->float(1.5);
        $result = new Variable();

        $result->bitwiseOp(OpCode::TYPE_SHIFT_LEFT, $left, $right);

        self::assertSame(Variable::TYPE_INTEGER, $result->type);
        self::assertSame(2, $result->toInt());
    }

    public function testFloatShiftIntTruncates(): void
    {
        $left = new Variable(Variable::TYPE_FLOAT);
        $left->float(1.5);
        $right = new Variable(Variable::TYPE_INTEGER);
        $right->int(1);
        $result = new Variable();

        $result->bitwiseOp(OpCode::TYPE_SHIFT_LEFT, $left, $right);

        self::assertSame(Variable::TYPE_INTEGER, $result->type);
        self::assertSame(2, $result->toInt());
    }

    public function testIntShiftRightFloatTruncates(): void
    {
        $left = new Variable(Variable::TYPE_INTEGER);
        $left->int(8);
        $right = new Variable(Variable::TYPE_FLOAT);
        $right->float(1.5);
        $result = new Variable();

        $result->bitwiseOp(OpCode::TYPE_SHIFT_RIGHT, $left, $right);

        self::assertSame(Variable::TYPE_INTEGER, $result->type);
        self::assertSame(4, $result->toInt());
    }
}
