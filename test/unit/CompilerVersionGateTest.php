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

    public function testSupportsStrIncrementTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::supportsStrIncrement());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testAdvertisesStrIncrementFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::advertisesStrIncrement());
    }

    public function testAdvertisesStrIncrementTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::advertisesStrIncrement());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
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

    public function testSupportsReadonlyAnonymousClassFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsReadonlyAnonymousClass());
    }

    public function testSupportsReadonlyAnonymousClassTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::supportsReadonlyAnonymousClass());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsTypedFunctionStaticFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsTypedFunctionStatic());
    }

    public function testSupportsTypedFunctionStaticTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::supportsTypedFunctionStatic());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsMbStrPadFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsMbStrPad());
    }

    public function testSupportsMbStrPadTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsMbStrPad());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsHex2binStrictFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsHex2binStrict());
    }

    public function testSupportsHex2binStrictTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsHex2binStrict());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsFpowFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsFpow());
    }

    public function testSupportsBcmathFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsBcmath());
    }

    public function testSupportsBcmathTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsBcmath());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsNextafterFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsNextafter());
    }

    public function testSupportsNextafterTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsNextafter());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsRoundingModeEnumFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsRoundingModeEnum());
    }

    public function testSupportsJsonValidateFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsJsonValidate());
    }

    public function testSupportsJsonValidateTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsJsonValidate());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
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

    public function testSupportsGcStatusPhp84SchemaTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsGcStatusPhp84Schema());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsReflectionPropertyAccessProbesFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsReflectionPropertyAccessProbes());
    }

    public function testSupportsReflectionPropertyAccessProbesTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsReflectionPropertyAccessProbes());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsReflectionPropertyIsDynamicFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsReflectionPropertyIsDynamic());
    }

    public function testSupportsReflectionPropertyIsDynamicTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsReflectionPropertyIsDynamic());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsReflectionEnumUnitCaseIsDeprecatedFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsReflectionEnumUnitCaseIsDeprecated());
    }

    public function testSupportsReflectionEnumUnitCaseIsDeprecatedTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsReflectionEnumUnitCaseIsDeprecated());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
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

    public function testSupportsHttpLastResponseHeadersTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsHttpLastResponseHeaders());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsStreamContextSetOptionsWithheldOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsStreamContextSetOptions());
    }

    public function testSupportsStreamContextSetOptionsTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsStreamContextSetOptions());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsPipeOperatorFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsPipeOperator());
    }

    public function testSupportsCloneWithSyntaxFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsCloneWithSyntax());
    }

    public function testSupportsAsymmetricVisibilityTrueOn84DevLine(): void
    {
        $this->assertTrue(CompilerVersion::supportsAsymmetricVisibility());
    }

    public function testSupportsAsymmetricVisibilityFalseWhenProfile82(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsAsymmetricVisibility());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsAsymmetricVisibilityTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsAsymmetricVisibility());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsPropertyHooksFalseOn84DevReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $this->assertFalse(CompilerVersion::supportsPropertyHooks());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsPropertyHooksFalseWhenProfile82(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsPropertyHooks());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsPropertyHooksTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsPropertyHooks());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsGetDeclaredExcludeDeprecatedFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsGetDeclaredExcludeDeprecated());
    }

    public function testSupportsDeprecatedAttributeRuntimeNoticesFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsDeprecatedAttributeRuntimeNotices());
    }

    public function testSupportsDeprecatedAttributeRuntimeNoticesTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsDeprecatedAttributeRuntimeNotices());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsExitFunctionFormFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsExitFunctionForm());
    }

    public function testSupportsExitFunctionFormTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsExitFunctionForm());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsTypedTraitConstantsTrueOn83Target(): void
    {
        $this->assertTrue(CompilerVersion::supportsTypedTraitConstants());
    }

    public function testSupportsTypedClassConstantsFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsTypedClassConstants());
    }

    public function testSupportsTypedClassConstantsFalseWhenProfile82(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsTypedClassConstants());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsTypedClassConstantsTrueWhenProfile83(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::supportsTypedClassConstants());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsClassConstObjectExpressionsFalseOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $this->assertFalse(CompilerVersion::supportsClassConstObjectExpressions());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsClassConstObjectExpressionsFalseWhenProfile82(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsClassConstObjectExpressions());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsClassConstObjectExpressionsFalseWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertFalse(CompilerVersion::supportsClassConstObjectExpressions());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsInterfaceTypedConstantsTrueOn83Target(): void
    {
        $this->assertTrue(CompilerVersion::supportsInterfaceTypedConstants());
    }

    public function testSupportsOverrideAttributeFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsOverrideAttribute());
    }

    public function testSupportsOverrideAttributeFalseWhenProfile82(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsOverrideAttribute());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsOverrideAttributeTrueWhenProfile83(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::supportsOverrideAttribute());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsOverrideAttributeTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsOverrideAttribute());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsFinalGlobalTypedConstantsAlwaysFalse(): void
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

    public function testVmRegistersStrIncrementOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            $this->assertTrue(isset($ctx->functions['str_decrement']));
            $this->assertTrue(isset($ctx->functions['str_increment']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
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

    public function testVmRegistersMbStrPadOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $this->assertTrue(isset($runtime->vmContext->functions['mb_str_pad']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
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

    public function testVmDoesNotRegisterNextafterOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->functions['nextafter']));
    }

    public function testVmRegistersNextafterOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $this->assertTrue(isset($runtime->vmContext->functions['nextafter']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
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

    public function testVmDoesNotRegisterJsonValidateOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->functions['json_validate']));
    }

    public function testVmRegistersJsonValidateOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $this->assertTrue(isset($runtime->vmContext->functions['json_validate']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
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

    public function testSupportsMbTrimFunctionsTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsMbTrimFunctions());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmRegistersMbTrimOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            foreach (['mb_trim', 'mb_ltrim', 'mb_rtrim'] as $fn) {
                $this->assertTrue(isset($ctx->functions[$fn]), $fn);
            }
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmDoesNotRegisterStreamContextSetOptionsOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $this->assertFalse(isset($ctx->functions['stream_context_set_options']));
        $this->assertTrue(isset($ctx->functions['stream_context_get_options']));
    }

    public function testSupportsStreamContextSetOptionsFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsStreamContextSetOptions());
    }

    public function testSupportsStreamContextSetOptionsTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsStreamContextSetOptions());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmRegistersStreamContextSetOptionsOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $this->assertTrue(isset($runtime->vmContext->functions['stream_context_set_options']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
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

    public function testSupportsClosureGetCurrentFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsClosureGetCurrent());
    }

    public function testSupportsClosureGetCurrentTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsClosureGetCurrent());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsBareRethrowFalseOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsBareRethrow());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsBareRethrowFalseOnDefaultDevProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $this->assertFalse(CompilerVersion::supportsBareRethrow());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsBareRethrowTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsBareRethrow());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmDoesNotRegisterClosureGetCurrentOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $closure = $runtime->vmContext->classes['closure'] ?? null;
        $this->assertNotNull($closure);
        $this->assertFalse(isset($closure->methods['getcurrent']));
    }

    public function testVmRegistersClosureGetCurrentOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $closure = $runtime->vmContext->classes['closure'] ?? null;
            $this->assertNotNull($closure);
            $this->assertTrue(isset($closure->methods['getcurrent']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDomNodeContainsOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsDomNodeContains());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDomNodeContainsFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsDomNodeContains());
    }

    public function testSupportsDomNodeIsEqualNodeFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsDomNodeIsEqualNode());
    }

    public function testVmRegistersDomNodeContainsOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $node = $runtime->vmContext->classes['domnode'] ?? null;
            $this->assertNotNull($node);
            $this->assertTrue(isset($node->methods['contains']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDomNodeCompareDocumentPositionOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsDomNodeCompareDocumentPosition());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDomNodeCompareDocumentPositionFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsDomNodeCompareDocumentPosition());
    }

    public function testSupportsDomNodeGetRootNodeOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsDomNodeGetRootNode());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDomNodeGetRootNodeFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsDomNodeGetRootNode());
    }

    public function testVmRegistersDomNodeGetRootNodeOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $node = $runtime->vmContext->classes['domnode'] ?? null;
            $this->assertNotNull($node);
            $this->assertTrue(isset($node->methods['getrootnode']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDomElementInsertAdjacentHtmlOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsDomElementInsertAdjacentHtml());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsDomElementInsertAdjacentHtmlFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsDomElementInsertAdjacentHtml());
    }

    public function testVmRegistersDomElementInsertAdjacentHtmlOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $element = $runtime->vmContext->classes['domelement'] ?? null;
            $this->assertNotNull($element);
            $this->assertTrue(isset($element->methods['insertadjacenthtml']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
