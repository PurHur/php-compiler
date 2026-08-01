<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\PropertyVisibility;
use PHPCfg\Func as CfgFunc;
use PHPUnit\Framework\TestCase;

/**
 * unset() follows set-visibility with Zend "Cannot unset …" messages (#23338).
 */
final class AsymmetricUnsetVisibilityTest extends TestCase
{
    public function testPrivateSetDeniesGlobalUnset(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'Cannot unset private(set) property U::$name from global scope'
        );
        PropertyVisibility::assertUnsettable(
            CfgFunc::FLAG_PRIVATE,
            null,
            'u',
            'U',
            'name',
            static fn (): bool => false,
            CfgFunc::FLAG_PUBLIC,
            true
        );
    }

    public function testPrivateSetAllowsSameClassUnset(): void
    {
        PropertyVisibility::assertUnsettable(
            CfgFunc::FLAG_PRIVATE,
            'u',
            'u',
            'U',
            'name',
            static fn (): bool => false,
            CfgFunc::FLAG_PUBLIC,
            true
        );
        $this->addToAssertionCount(1);
    }

    public function testProtectedSetAllowsSubclassUnset(): void
    {
        PropertyVisibility::assertUnsettable(
            CfgFunc::FLAG_PROTECTED,
            'child',
            'parent',
            'Parent',
            'tag',
            static fn (string $child, string $parent): bool => $child === 'child' && $parent === 'parent',
            CfgFunc::FLAG_PUBLIC,
            true
        );
        $this->addToAssertionCount(1);
    }

    public function testPrivateSetDeniesChildScopeUnsetWithScopePrefix(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'Cannot unset private(set) property U::$name from scope Child'
        );
        PropertyVisibility::assertUnsettable(
            CfgFunc::FLAG_PRIVATE,
            'child',
            'u',
            'U',
            'name',
            static fn (): bool => false,
            CfgFunc::FLAG_PUBLIC,
            true,
            'Child'
        );
    }

    public function testProtectedSetDeniesUnrelatedScopeWithScopePrefix(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'Cannot unset protected(set) property Parent::$tag from scope Other'
        );
        PropertyVisibility::assertUnsettable(
            CfgFunc::FLAG_PROTECTED,
            'other',
            'parent',
            'Parent',
            'tag',
            static fn (): bool => false,
            CfgFunc::FLAG_PUBLIC,
            true,
            'Other'
        );
    }
}
