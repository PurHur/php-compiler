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

    public function testSupportsStrIncrementTrueOnForwardProfile(): void
    {
        $this->assertTrue(CompilerVersion::supportsStrIncrement());
    }

    public function testSupportsClassHasFunctionsTrueOnForwardProfile(): void
    {
        $this->assertTrue(CompilerVersion::supportsClassHasFunctions());
    }

    public function testSupportsPhp84ReflectionProbeBuiltinsFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsPhp84ReflectionProbeBuiltins());
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

    public function testSupportsNextafterFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsNextafter());
    }

    public function testSupportsRoundingModeEnumFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsRoundingModeEnum());
    }

    public function testSupportsJsonValidateTrueOnForwardProfile(): void
    {
        $this->assertTrue(CompilerVersion::supportsJsonValidate());
    }

    public function testAdvertisesReflectionConstantClassFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::advertisesReflectionConstantClass());
    }

    public function testSupportsClockGettimeFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsClockGettime());
    }

    public function testSupportsBuiltinStubEnumsFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsBuiltinStubEnums());
    }

    public function testSupportsGcStatusPhp84SchemaFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsGcStatusPhp84Schema());
    }

    public function testSupportsReflectionPropertyAccessProbesTrueOn84DevForwardProfile(): void
    {
        $this->assertTrue(CompilerVersion::supportsReflectionPropertyAccessProbes());
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

    public function testSupportsHttpLastResponseHeadersWithheldOnReferenceProfile(): void
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

    public function testSupportsAsymmetricVisibilityTrueOnForwardProfile(): void
    {
        $this->assertTrue(CompilerVersion::supportsAsymmetricVisibility());
    }

    public function testSupportsPropertyHooksFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsPropertyHooks());
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

    public function testSupportsFinalGlobalTypedConstantsAlwaysFalse(): void
    {
        $this->assertFalse(CompilerVersion::supportsFinalGlobalTypedConstants());
    }

    public function testVmRegistersStrIncrementOnForwardProfile(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $this->assertTrue(isset($ctx->functions['str_decrement']));
        $this->assertTrue(isset($ctx->functions['str_increment']));
    }

    public function testVmRegistersClassHasFunctionsOnForwardProfile(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        foreach (['class_has_method', 'class_has_property', 'class_has_constant'] as $fn) {
            $this->assertTrue(isset($ctx->functions[$fn]), $fn);
        }
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

    public function testVmDoesNotRegisterRoundingModeOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->classes['roundingmode']));
    }

    public function testVmDoesNotRegisterFpowOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        foreach (['fpow', 'fmin', 'fmax'] as $fn) {
            $this->assertFalse(isset($ctx->functions[$fn]), $fn);
        }
    }

    public function testSupportsRandomIntervalBoundaryFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsRandomIntervalBoundary());
    }

    public function testVmDoesNotRegisterRandomIntervalBoundaryOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->classes['random\\intervalboundary']));
    }

    public function testVmRegistersJsonValidateOnForwardProfile(): void
    {
        $runtime = new Runtime();
        $this->assertTrue(isset($runtime->vmContext->functions['json_validate']));
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

    public function testVmRegistersReflectionPropertyAccessProbesOnForwardProfile(): void
    {
        $runtime = new Runtime();
        $rp = $runtime->vmContext->classes['reflectionproperty'] ?? null;
        $this->assertNotNull($rp);
        $this->assertTrue(isset($rp->methods['isreadable']));
        $this->assertTrue(isset($rp->methods['iswritable']));
    }

    public function testVmDoesNotRegisterArrayReplaceKeyOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->functions['array_replace_key']));
        $this->assertTrue(isset($runtime->vmContext->functions['array_replace']));
    }

    public function testSupportsClosureGetCurrentTrueOnForwardProfile(): void
    {
        $this->assertTrue(CompilerVersion::supportsClosureGetCurrent());
    }

    public function testDoesNotSupportBareRethrowOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsBareRethrow());
    }

    public function testVmRegistersClosureGetCurrentOnForwardProfile(): void
    {
        $runtime = new Runtime();
        $closure = $runtime->vmContext->classes['closure'] ?? null;
        $this->assertNotNull($closure);
        $this->assertTrue(isset($closure->methods['getcurrent']));
    }

    public function testSupportsDomNodeContainsFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsDomNodeContains());
    }

    public function testVmDoesNotRegisterDomNodeContainsOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $node = $runtime->vmContext->classes['domnode'] ?? null;
        $this->assertNotNull($node);
        $this->assertFalse(isset($node->methods['contains']));
    }

    public function testSupportsDomNodeGetRootNodeFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsDomNodeGetRootNode());
    }

    public function testVmDoesNotRegisterDomNodeGetRootNodeOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $node = $runtime->vmContext->classes['domnode'] ?? null;
        $this->assertNotNull($node);
        $this->assertFalse(isset($node->methods['getrootnode']));
    }
}
