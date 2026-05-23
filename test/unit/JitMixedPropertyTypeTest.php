<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPTypes\Type;
use PHPCompiler\JIT\Variable as JitVariable;
use PHPUnit\Framework\TestCase;

final class JitMixedPropertyTypeTest extends TestCase
{
    public function testMixedDocblockMapsToValueBoxType(): void
    {
        $mixed = Type::mixed();

        $this->assertSame(JitVariable::TYPE_VALUE, JitVariable::getTypeFromType($mixed));
    }

    public function testMixedDeclMapsToValueJitType(): void
    {
        $mixed = Type::fromDecl('mixed');

        $this->assertSame(JitVariable::TYPE_VALUE, JitVariable::getTypeFromType($mixed));
    }
}
