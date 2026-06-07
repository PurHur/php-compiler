<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\TypeCheck;
use PHPCompiler\VM\Variable;

/** Guard property hook get return type check (#7301). */
final class PropertyHookTypedReturnTest extends \PHPUnit\Framework\TestCase
{
    public function testGetHookWrongReturnTypeThrowsTypeError(): void
    {
        $prototype = new Variable();
        $prototype->resolveIndirect()->typeConstraint = Variable::TYPE_INTEGER;

        $value = new Variable();
        $value->string('not int');

        $this->expectException(\TypeError::class);
        TypeCheck::assertPropertyHookGetReturn($value, $prototype, false, new Context(new Runtime()));
    }

    public function testGetHookMatchingReturnTypePasses(): void
    {
        $prototype = new Variable();
        $prototype->resolveIndirect()->typeConstraint = Variable::TYPE_INTEGER;

        $value = new Variable();
        $value->int(42);

        TypeCheck::assertPropertyHookGetReturn($value, $prototype, false, new Context(new Runtime()));
        self::assertSame(42, $value->resolveIndirect()->toInt());
    }
}
