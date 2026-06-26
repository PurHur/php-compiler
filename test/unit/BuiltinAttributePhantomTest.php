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
        $this->assertTrue(CompilerVersion::advertisesDeprecatedAttributeClass());
        $this->assertTrue(CompilerVersion::advertisesNoDiscardAttributeClass());
        $this->assertTrue(CompilerVersion::advertisesDelayedTargetValidationAttributeClass());
        $this->assertTrue(CompilerVersion::advertisesCompileTimeAttributeClass());
    }

    public function testVmRegistersForwardCompatAttributeClassesOn84DevProfile(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $this->assertTrue(isset($ctx->classes['override']));
        $this->assertTrue(isset($ctx->classes['deprecated']));
        $this->assertTrue(isset($ctx->classes['nodiscard']));
        $this->assertTrue(isset($ctx->classes['delayedtargetvalidation']));
        $this->assertTrue(isset($ctx->classes['compiletime']));
    }
}
