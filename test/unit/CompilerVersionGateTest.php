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

    public function testSupportsClassUsesRecursiveFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsClassUsesRecursive());
    }

    public function testSupportsMbStrPadFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsMbStrPad());
    }

    public function testSupportsFpowFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsFpow());
    }

    public function testSupportsRoundingModeEnumTrueOn84DevForwardProfile(): void
    {
        $this->assertTrue(CompilerVersion::supportsRoundingModeEnum());
    }

    public function testSupportsClockGettimeFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsClockGettime());
    }

    public function testSupportsGcStatusPhp84SchemaFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsGcStatusPhp84Schema());
    }

    public function testSupportsHrtimeAsNumberFloatFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsHrtimeAsNumberFloat());
    }

    public function testSupportsClassConstantsFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsClassConstants());
    }

    public function testSupportsHeaderListFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsHeaderList());
    }

    public function testSupportsArrayReplaceKeyFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsArrayReplaceKey());
    }

    public function testSupportsHttpLastResponseHeadersFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsHttpLastResponseHeaders());
    }

    public function testSupportsPipeOperatorFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsPipeOperator());
    }

    public function testSupportsCloneWithSyntaxFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsCloneWithSyntax());
    }

    public function testSupportsAsymmetricVisibilityTrueOn84DevTarget(): void
    {
        $this->assertTrue(CompilerVersion::supportsAsymmetricVisibility());
    }

    public function testSupportsPropertyHooksTrueOn84DevTarget(): void
    {
        $this->assertTrue(CompilerVersion::supportsPropertyHooks());
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

    public function testSupportsTypedClassConstantsFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsTypedClassConstants());
    }

    public function testSupportsClassConstObjectExpressionsFalseOnReferenceProfile(): void
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

    public function testVmDoesNotRegisterMbStrPadOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->functions['mb_str_pad']));
    }

    public function testVmDoesNotRegisterClockGettimeOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $this->assertFalse(isset($ctx->functions['clock_gettime']));
        $this->assertFalse(isset($ctx->classes['clockinterface']));
    }

    public function testVmRegistersRoundingModeOnForwardProfile(): void
    {
        $runtime = new Runtime();
        $this->assertTrue(isset($runtime->vmContext->classes['roundingmode']));
    }

    public function testSupportsMbTrimFunctionsFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsMbTrimFunctions());
    }

    public function testVmDoesNotRegisterMbTrimOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        foreach (['mb_trim', 'mb_ltrim', 'mb_rtrim'] as $fn) {
            $this->assertFalse(isset($ctx->functions[$fn]), $fn);
        }
    }

    public function testVmDoesNotRegisterStreamContextSetOptionsOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $this->assertFalse(isset($ctx->functions['stream_context_set_options']));
        $this->assertTrue(isset($ctx->functions['stream_context_get_options']));
    }

    public function testVmDoesNotRegisterClassConstantsOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->functions['class_constants']));
    }

    public function testVmDoesNotRegisterHeaderListOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $this->assertFalse(isset($ctx->functions['header_list']));
        $this->assertTrue(isset($ctx->functions['header']));
    }

    public function testVmDoesNotRegisterArrayReplaceKeyOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->functions['array_replace_key']));
        $this->assertTrue(isset($runtime->vmContext->functions['array_replace']));
    }

    public function testVmDoesNotRegisterHttpLastResponseHeadersOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        foreach (['http_get_last_response_headers', 'get_last_response_headers', 'http_clear_last_response_headers'] as $fn) {
            $this->assertFalse(isset($ctx->functions[$fn]), $fn);
        }
        $this->assertTrue(isset($ctx->functions['get_headers']));
    }
}
