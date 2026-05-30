<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Compiler\AttributeNames;
use PHPUnit\Framework\TestCase;

/** @covers AttributeNames::assertNoDuplicates (#3718) */
final class AttributeNamesDuplicateTest extends TestCase
{
    public function testRejectsDuplicateAllowDynamicProperties(): void
    {
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Attribute "AllowDynamicProperties" must not be repeated');

        AttributeNames::assertNoDuplicates(['AllowDynamicProperties', 'AllowDynamicProperties']);
    }

    public function testAllowsDistinctAttributes(): void
    {
        AttributeNames::assertNoDuplicates(['AllowDynamicProperties', 'SensitiveParameter']);
        $this->addToAssertionCount(1);
    }
}
