<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\VM;

use PHPCompiler\OpCode;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** Zend zend_operators.c unary + on non-numeric strings (#4723). */
final class UnaryPlusNonNumericTest extends TestCase
{
    public function testNonNumericStringCoercesToZero(): void
    {
        $src = new Variable(Variable::TYPE_STRING);
        $src->string('0x10');
        $result = new Variable();
        $result->unaryOp(OpCode::TYPE_UNARY_PLUS, $src);

        self::assertSame(Variable::TYPE_INTEGER, $result->type);
        self::assertSame(0, $result->toInt());
    }

    public function testNumericStringCoercesToInt(): void
    {
        $src = new Variable(Variable::TYPE_STRING);
        $src->string('42');
        $result = new Variable();
        $result->unaryOp(OpCode::TYPE_UNARY_PLUS, $src);

        self::assertSame(Variable::TYPE_INTEGER, $result->type);
        self::assertSame(42, $result->toInt());
    }

    public function testNumericPrefixStringCoercesToPrefix(): void
    {
        $src = new Variable(Variable::TYPE_STRING);
        $src->string('5x');
        $result = new Variable();
        $result->unaryOp(OpCode::TYPE_UNARY_PLUS, $src);

        self::assertSame(Variable::TYPE_INTEGER, $result->type);
        self::assertSame(5, $result->toInt());
    }
}
