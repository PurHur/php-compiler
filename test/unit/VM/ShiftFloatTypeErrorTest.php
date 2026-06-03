<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\VM;

use PHPCompiler\OpCode;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** Zend shift_left_function / shift_right_function float operands (#5008). */
final class ShiftFloatTypeErrorTest extends TestCase
{
    public function testIntShiftFloatThrows(): void
    {
        $left = new Variable(Variable::TYPE_INTEGER);
        $left->int(1);
        $right = new Variable(Variable::TYPE_FLOAT);
        $right->float(1.5);
        $result = new Variable();

        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('Unsupported operand types: int << float');
        $result->bitwiseOp(OpCode::TYPE_SHIFT_LEFT, $left, $right);
    }

    public function testFloatShiftIntThrows(): void
    {
        $left = new Variable(Variable::TYPE_FLOAT);
        $left->float(1.5);
        $right = new Variable(Variable::TYPE_INTEGER);
        $right->int(1);
        $result = new Variable();

        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('Unsupported operand types: float << int');
        $result->bitwiseOp(OpCode::TYPE_SHIFT_LEFT, $left, $right);
    }

    public function testIntShiftRightFloatThrows(): void
    {
        $left = new Variable(Variable::TYPE_INTEGER);
        $left->int(1);
        $right = new Variable(Variable::TYPE_FLOAT);
        $right->float(1.5);
        $result = new Variable();

        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('Unsupported operand types: int >> float');
        $result->bitwiseOp(OpCode::TYPE_SHIFT_RIGHT, $left, $right);
    }
}
