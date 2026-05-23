<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPTypes\Type;
use PHPCompiler\JIT\Variable;

final class JitMixedPropertyTypeTest extends TestCase
{
    public function testMixedDeclMapsToValueJitType(): void
    {
        $mixed = Type::fromDecl('mixed');
        self::assertSame(Variable::TYPE_VALUE, Variable::getTypeFromType($mixed));
    }
}
