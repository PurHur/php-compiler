<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand\Literal;
use PHPCfg\Operand\Temporary;
use PHPCfg\Operand\Variable as CfgVariable;
use PHPUnit\Framework\TestCase;

/**
 * php-cfg stores ${1} as an int-name Literal; resolveVariableName must yield "1" (#22776).
 */
final class BlockResolveNumericVariableNameTest extends TestCase
{
    public function testNumericBraceVariableNameResolvesToString(): void
    {
        $op = new Temporary(new CfgVariable(new Literal(1)));
        self::assertSame('1', Block::resolveVariableName($op));
    }

    public function testNamedBraceVariableNameStillResolves(): void
    {
        $op = new Temporary(new CfgVariable(new Literal('missing')));
        self::assertSame('missing', Block::resolveVariableName($op));
    }

    public function testFloatNumericNameCoercesToString(): void
    {
        $op = new Temporary(new CfgVariable(new Literal(2.0)));
        self::assertSame('2', Block::resolveVariableName($op));
    }
}
