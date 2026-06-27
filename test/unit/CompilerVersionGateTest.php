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

    public function testSupportsStrIncrementFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsStrIncrement());
    }

    public function testSupportsFpowFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsFpow());
    }

    public function testSupportsPipeOperatorFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsPipeOperator());
    }

    public function testSupportsAsymmetricVisibilityFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsAsymmetricVisibility());
    }

    public function testSupportsGetDeclaredExcludeDeprecatedFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsGetDeclaredExcludeDeprecated());
    }

    public function testSupportsExitFunctionFormFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsExitFunctionForm());
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

    public function testSupportsOverrideAttributeFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsOverrideAttribute());
    }

    public function testSupportsFinalGlobalTypedConstantsFalseOn84DevTarget(): void
    {
        $this->assertFalse(CompilerVersion::supportsFinalGlobalTypedConstants());
    }

    public function testVmDoesNotRegisterStrIncrementOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $this->assertFalse(isset($ctx->functions['str_decrement']));
        $this->assertFalse(isset($ctx->functions['str_increment']));
    }
}
