<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** CompilerVersion gates for PHP 8.3+ surface (#5697, #5212, #5993). */
final class CompilerVersionGateTest extends TestCase
{
    public function testVersionReports84Dev(): void
    {
        $this->assertSame('8.4.0-dev', CompilerVersion::VERSION);
    }

    public function testSupportsStrIncrementTrueOn83Target(): void
    {
        $this->assertTrue(CompilerVersion::supportsStrIncrement());
    }

    public function testSupportsTypedTraitConstantsTrueOn83Target(): void
    {
        $this->assertTrue(CompilerVersion::supportsTypedTraitConstants());
    }

    public function testSupportsClassConstObjectExpressionsFalse(): void
    {
        $this->assertFalse(CompilerVersion::supportsClassConstObjectExpressions());
    }

    public function testSupportsInterfaceTypedConstantsTrueOn83Target(): void
    {
        $this->assertTrue(CompilerVersion::supportsInterfaceTypedConstants());
    }

    public function testSupportsOverrideAttributeTrueOn83Target(): void
    {
        $this->assertTrue(CompilerVersion::supportsOverrideAttribute());
    }

    public function testSupportsFinalGlobalTypedConstantsFalseOn84DevTarget(): void
    {
        $this->assertFalse(CompilerVersion::supportsFinalGlobalTypedConstants());
    }

    public function testVmRegistersStrIncrementOn83Target(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $this->assertTrue(isset($ctx->functions['str_decrement']));
        $this->assertTrue(isset($ctx->functions['str_increment']));
    }
}
