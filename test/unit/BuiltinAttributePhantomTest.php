<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #11902, #12328 */
final class BuiltinAttributePhantomTest extends TestCase
{
    public function testForwardCompatAttributeClassesAdvertisedOn84DevProfile(): void
    {
        $this->assertTrue(CompilerVersion::advertisesOverrideAttributeClass());
        $this->assertFalse(CompilerVersion::advertisesDeprecatedAttributeClass());
        $this->assertFalse(CompilerVersion::advertisesNoDiscardAttributeClass());
        $this->assertTrue(CompilerVersion::advertisesEnumCasesAttributeClass());
        $this->assertFalse(CompilerVersion::advertisesDelayedTargetValidationAttributeClass());
        $this->assertFalse(CompilerVersion::advertisesCompileTimeAttributeClass());
    }

    public function testVmRegistersForwardCompatAttributeClassesOn84DevProfile(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $this->assertTrue(isset($ctx->classes['override']));
        $this->assertFalse(isset($ctx->classes['deprecated']));
        $this->assertFalse(isset($ctx->classes['nodiscard']));
        $this->assertTrue(isset($ctx->classes['enumcases']));
        $this->assertFalse(isset($ctx->classes['delayedtargetvalidation']));
        $this->assertFalse(isset($ctx->classes['compiletime']));
    }
}
