<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\VM;

use PHPCompiler\OpCode;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** Exact float bitwise operands coerce to Zend ints without changing result type (#23755). */
final class BitwiseFloatCoercionTest extends TestCase
{
    public function testExactFloatAndReturnsInt(): void
    {
        $left = new Variable(Variable::TYPE_FLOAT);
        $left->float(5.0);
        $right = new Variable(Variable::TYPE_INTEGER);
        $right->int(3);
        $result = new Variable();

        $result->bitwiseOp(OpCode::TYPE_BITWISE_AND, $left, $right);

        self::assertSame(Variable::TYPE_INTEGER, $result->type);
        self::assertSame(1, $result->toInt());
    }

    public function testExactFloatOrReturnsInt(): void
    {
        $left = new Variable(Variable::TYPE_FLOAT);
        $left->float(5.0);
        $right = new Variable(Variable::TYPE_INTEGER);
        $right->int(3);
        $result = new Variable();

        $result->bitwiseOp(OpCode::TYPE_BITWISE_OR, $left, $right);

        self::assertSame(Variable::TYPE_INTEGER, $result->type);
        self::assertSame(7, $result->toInt());
    }

    public function testExactFloatXorReturnsInt(): void
    {
        $left = new Variable(Variable::TYPE_FLOAT);
        $left->float(5.0);
        $right = new Variable(Variable::TYPE_INTEGER);
        $right->int(3);
        $result = new Variable();

        $result->bitwiseOp(OpCode::TYPE_BITWISE_XOR, $left, $right);

        self::assertSame(Variable::TYPE_INTEGER, $result->type);
        self::assertSame(6, $result->toInt());
    }
}
