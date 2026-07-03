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

    public function testSupportsAsymmetricVisibilityFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsAsymmetricVisibility());
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

    public function testSupportsPropertyHooksTrueOnForwardProfile(): void
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

    public function testSupportsClassConstObjectExpressionsTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsClassConstObjectExpressions());
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

    public function testSupportsBareRethrowTrueOnForwardDevProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
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
}
