<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Builtin advertisement profile gates (#11842, #12327, #12328). */
final class CompilerVersionBuiltinAdvertisementTest extends TestCase
{
    public function testBuiltinAdvertisementVersionMatches84DevLine(): void
    {
        $this->assertSame('8.4.0', CompilerVersion::builtinAdvertisementVersion());
    }

    public function testZendVersionMatchesReferenceProfile(): void
    {
        $this->assertSame('4.2.31', CompilerVersion::zendVersion());
    }

    public function testZendThreadIdWithheldOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsZendThreadId());
    }

    public function testSortingEnumWithheldOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsSortingEnum());
    }

    public function testRangeWithheldOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsRange());
    }

    public function testBuiltinStubEnumsWithheldOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsBuiltinStubEnums());
    }

    public function testJsonValidateWithheldOnDefault84DevReference(): void
    {
        $this->assertFalse(CompilerVersion::supportsJsonValidate());
        $this->assertFalse(CompilerVersion::advertisesJsonValidate());
    }

    public function testJsonValidateWithheldOn82Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsJsonValidate());
            $this->assertFalse(CompilerVersion::advertisesJsonValidate());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testJsonValidateAdvertisedOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsJsonValidate());
            $this->assertTrue(CompilerVersion::advertisesJsonValidate());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testMbStrPadWithheldOnDefault84DevReference(): void
    {
        $this->assertFalse(CompilerVersion::supportsMbStrPad());
        $this->assertFalse(CompilerVersion::advertisesMbStrPad());
    }

    public function testMbStrPadWithheldOn82Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsMbStrPad());
            $this->assertFalse(CompilerVersion::advertisesMbStrPad());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testMbUcfirstLcfirstWithheldOnReferenceProfileUntilStable84(): void
    {
        $this->assertFalse(CompilerVersion::supportsMbUcfirstLcfirst());
        $this->assertFalse(CompilerVersion::advertisesMbUcfirstLcfirst());
    }

    public function testGetObjectIdWithheldOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsGetObjectId());
        $this->assertFalse(CompilerVersion::advertisesGetObjectId());
    }

    public function testGetObjectIdAdvertisedOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::supportsGetObjectId());
            $this->assertTrue(CompilerVersion::advertisesGetObjectId());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testStrIncrementWithheldOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsStrIncrement());
        $this->assertFalse(CompilerVersion::advertisesStrIncrement());
    }

    public function testStrIncrementAdvertisedOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::supportsStrIncrement());
            $this->assertTrue(CompilerVersion::advertisesStrIncrement());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testClassHasFunctionsWithheldOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsClassHasFunctions());
    }

    public function testClassHasFunctionsAdvertisedOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsClassHasFunctions());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testPhp84ReflectionProbeBuiltinsWithheldOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $this->assertFalse(CompilerVersion::supportsPhp84ReflectionProbeBuiltins());
            $this->assertFalse(CompilerVersion::advertisesPhp84ReflectionProbeBuiltins());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testPhp84ReflectionProbeBuiltinsWithheldOnPhp82Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsPhp84ReflectionProbeBuiltins());
            $this->assertFalse(CompilerVersion::advertisesPhp84ReflectionProbeBuiltins());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testReflectionCreateFromFactoriesWithheldOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsReflectionCreateFromFactories());
    }

    public function testClassUsesRecursiveWithheldOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $this->assertFalse(CompilerVersion::supportsClassUsesRecursive());
            $this->assertFalse(CompilerVersion::advertisesClassUsesRecursive());
            $runtime = new Runtime();
            $this->assertFalse(isset($runtime->vmContext->functions['class_uses_recursive']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testClassUsesRecursiveAdvertisedOn83ForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::supportsClassUsesRecursive());
            $this->assertTrue(CompilerVersion::advertisesClassUsesRecursive());
            $runtime = new Runtime();
            $this->assertTrue(isset($runtime->vmContext->functions['class_uses_recursive']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testClassUsesRecursiveWithheldOnPhp82Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsClassUsesRecursive());
            $this->assertFalse(CompilerVersion::advertisesClassUsesRecursive());
            $runtime = new Runtime();
            $this->assertFalse(isset($runtime->vmContext->functions['class_uses_recursive']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testFpowWithheldOnReferenceProfileUntilStable84(): void
    {
        $this->assertFalse(CompilerVersion::supportsFpow());
    }

    public function testBcmathWithheldOnReferenceProfileUntilStable84(): void
    {
        $this->assertFalse(CompilerVersion::supportsBcmath());
    }

    public function testBcmathAdvertisedOnForwardProfile(): void
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

    public function testNextafterWithheldOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsNextafter());
    }

    public function testNextafterAdvertisedOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsNextafter());
            $this->assertTrue(CompilerVersion::advertisesNextafter());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testFpowAdvertisedOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsFpow());
            $this->assertTrue(CompilerVersion::advertisesFpow());
            $this->assertTrue(CompilerVersion::advertisesNextafter());
            $this->assertTrue(CompilerVersion::supportsBcmath());
            $this->assertTrue(CompilerVersion::advertisesBcround());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testMbStrPadAdvertisedOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsMbStrPad());
            $this->assertTrue(CompilerVersion::advertisesMbStrPad());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testMbUcfirstLcfirstAdvertisedOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsMbUcfirstLcfirst());
            $this->assertTrue(CompilerVersion::advertisesMbUcfirstLcfirst());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testStreamSupportsAdvertisedOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::supportsStreamSupports());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testStreamSupportsAdvertisedOn83ForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::supportsStreamSupports());
            $runtime = new Runtime();
            $this->assertTrue(isset($runtime->vmContext->functions['stream_supports']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testPhp84ReflectionProbeBuiltinsAdvertisedOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsPhp84ReflectionProbeBuiltins());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testRoundingModeEnumAdvertisedOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsRoundingModeEnum());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testDeprecatedAttributeClassAdvertisedOnForwardProfile(): void
    {
        $this->assertFalse(CompilerVersion::advertisesDeprecatedAttributeClass());
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::advertisesDeprecatedAttributeClass());
            $runtime = new Runtime();
            $this->assertTrue(isset($runtime->vmContext->classes['deprecated']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmRegistersFpowFamilyOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            foreach (['fpow', 'fmin', 'fmax', 'fadd', 'fsub', 'fmul', 'stream_supports', 'attribute_exists'] as $fn) {
                $this->assertTrue(isset($ctx->functions[$fn]), $fn);
            }
            $this->assertTrue(isset($ctx->classes['roundingmode']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
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

    public function testRoundingModeEnumWithheldOnReferenceProfileUntilStable84(): void
    {
        $this->assertFalse(CompilerVersion::supportsRoundingModeEnum());
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
        foreach (['fpow', 'fmin', 'fmax', 'fadd', 'fsub', 'fmul'] as $fn) {
            $this->assertFalse(isset($ctx->functions[$fn]), $fn);
        }
    }

    public function testRandomIntervalBoundaryWithheldOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsRandomIntervalBoundary());
    }

    public function testVmDoesNotRegisterRandomIntervalBoundaryOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->classes['random\\intervalboundary']));
    }

    public function testReadonlyBuiltinWithheldOnReferenceProfileUntilStable84(): void
    {
        $this->assertFalse(CompilerVersion::supportsReadonlyBuiltin());
        $this->assertFalse(CompilerVersion::advertisesReadonlyBuiltin());
    }

    public function testZendThreadIdAdvertisementWithheldOnReferenceProfileUntilStable84(): void
    {
        $this->assertFalse(CompilerVersion::supportsZendThreadId());
        $this->assertFalse(CompilerVersion::advertisesZendThreadId());
    }

    public function testStreamSupportsWithheldOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsStreamSupports());
    }

    public function testPhp83ArrayKeyFunctionsWithheldOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsPhp83ArrayKeyFunctions());
    }

    public function testPhp84ArraySearchFunctionsWithheldOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsPhp84ArraySearchFunctions());
    }

    public function testGeneratorToArrayEnabledOnDefaultDevProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $this->assertTrue(CompilerVersion::supportsGeneratorToArray());
            $this->assertTrue(CompilerVersion::advertisesGeneratorToArray());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testGeneratorToArrayWithheldOnPhp82Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsGeneratorToArray());
            $this->assertFalse(CompilerVersion::advertisesGeneratorToArray());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testPhp83ArrayKeyFunctionsTrueWhenProfile83(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::supportsPhp83ArrayKeyFunctions());
            $this->assertFalse(CompilerVersion::supportsPhp84ArraySearchFunctions());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testPhp84ArraySearchFunctionsTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsPhp83ArrayKeyFunctions());
            $this->assertTrue(CompilerVersion::supportsPhp84ArraySearchFunctions());
            $this->assertFalse(CompilerVersion::supportsPhp85ArrayFirstLast());
            $this->assertFalse(CompilerVersion::advertisesPhp85ArrayFirstLast());
            $this->assertTrue(CompilerVersion::supportsGeneratorToArray());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testPhp85ArrayFirstLastTrueWhenProfile85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $this->assertTrue(CompilerVersion::supportsPhp85ArrayFirstLast());
            $this->assertTrue(CompilerVersion::advertisesPhp85ArrayFirstLast());
            $this->assertTrue(CompilerVersion::supportsPhp84ArraySearchFunctions());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testPhp85ArrayFirstLastWithheldOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsPhp85ArrayFirstLast());
        $this->assertFalse(CompilerVersion::advertisesPhp85ArrayFirstLast());
    }

    public function testDateTimeMicrosecondWithheldOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsDateTimeMicrosecond());
    }

    public function testDateTimeMicrosecondWithheldWhenProfile82(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsDateTimeMicrosecond());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testDateTimeMicrosecondTrueWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsDateTimeMicrosecond());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testConvertCyrStringNotAdvertisedOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsConvertCyrString());
        $this->assertFalse(CompilerVersion::advertisesConvertCyrString());
    }

    public function testMoneyFormatNotAdvertisedOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsMoneyFormat());
        $this->assertFalse(CompilerVersion::advertisesMoneyFormat());
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->functions['money_format']));
    }

    public function testStrxfrmNotAdvertisedOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsStrxfrm());
    }

    public function testGetmygrgidNotAdvertisedOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsGetmygrgid());
    }

    public function testDisktotalspaceNotAdvertisedOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsDisktotalspace());
        $this->assertFalse(CompilerVersion::advertisesDisktotalspace());
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->functions['disktotalspace']));
    }

    public function testForwardGatedBuiltinsRegisteredOnProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsStrxfrm());
            $this->assertFalse(CompilerVersion::supportsConvertCyrString());
            $this->assertFalse(CompilerVersion::supportsMoneyFormat());
            $this->assertTrue(CompilerVersion::supportsGetmygrgid());
            $runtime = new Runtime();
            foreach (['strxfrm', 'getmygrgid'] as $fn) {
                $this->assertTrue(isset($runtime->vmContext->functions[$fn]), $fn);
            }
            $this->assertFalse(isset($runtime->vmContext->functions['convert_cyr_string']));
            $this->assertFalse(isset($runtime->vmContext->functions['money_format']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** convert_cyr_string()/money_format() removed in php-src 8.0 — must not register on 8.4 (#21481). */
    public function testRemovedStdlibBuiltinsWithheldOnPhp84Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertFalse(CompilerVersion::supportsConvertCyrString());
            $this->assertFalse(CompilerVersion::supportsMoneyFormat());
            $runtime = new Runtime();
            $this->assertFalse(isset($runtime->vmContext->functions['convert_cyr_string']));
            $this->assertFalse(isset($runtime->vmContext->functions['money_format']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testRemovedStdlibBuiltinsRegisteredOnPhp74LegacyProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=7.4');
        try {
            $this->assertTrue(CompilerVersion::supportsConvertCyrString());
            $this->assertTrue(CompilerVersion::supportsMoneyFormat());
            $runtime = new Runtime();
            $this->assertTrue(isset($runtime->vmContext->functions['convert_cyr_string']));
            $this->assertTrue(isset($runtime->vmContext->functions['money_format']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testCrc32cWithheldOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $this->assertFalse(CompilerVersion::supportsCrc32c());
            $this->assertFalse(CompilerVersion::advertisesCrc32c());
            $runtime = new Runtime();
            $this->assertFalse(isset($runtime->vmContext->functions['crc32c']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testCrc32cWithheldOnPhp82Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsCrc32c());
            $this->assertFalse(CompilerVersion::advertisesCrc32c());
            $runtime = new Runtime();
            $this->assertFalse(isset($runtime->vmContext->functions['crc32c']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** hebrevc() removed in php-src 8.0 — must not register on 8.2/8.4 (#20354). */
    public function testHebrevcWithheldOnPhp84Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertFalse(CompilerVersion::supportsHebrevc());
            $this->assertFalse(CompilerVersion::advertisesHebrevc());
            $runtime = new Runtime();
            $this->assertFalse(isset($runtime->vmContext->functions['hebrevc']));
            $this->assertTrue(isset($runtime->vmContext->functions['hebrev']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testHebrevcRegisteredOnPhp74LegacyProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=7.4');
        try {
            $this->assertTrue(CompilerVersion::supportsHebrevc());
            $this->assertTrue(CompilerVersion::advertisesHebrevc());
            $runtime = new Runtime();
            $this->assertTrue(isset($runtime->vmContext->functions['hebrevc']));
            $this->assertTrue(isset($runtime->vmContext->functions['hebrev']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmDoesNotRegisterZendThreadIdOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->functions['zend_thread_id']));
    }

    public function testVmDoesNotRegisterClassUsesRecursiveOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        $runtime = new Runtime();
        try {
            $this->assertFalse(isset($runtime->vmContext->functions['class_uses_recursive']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmDoesNotRegisterPhp84ReflectionProbeBuiltinsOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            foreach (['attribute_exists', 'class_meth_exists', 'unitenum_exists', 'isAnonymousClass'] as $fn) {
                $this->assertFalse(isset($ctx->functions[$fn]), $fn);
            }
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmDoesNotRegisterPhp84ReflectionProbeBuiltinsOnPhp82Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            foreach (['attribute_exists', 'class_meth_exists', 'unitenum_exists', 'isAnonymousClass'] as $fn) {
                $this->assertFalse(isset($ctx->functions[$fn]), $fn);
            }
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmDoesNotRegisterSortingEnumOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $this->assertFalse(isset($ctx->classes['sorting']));
        $this->assertFalse(isset($ctx->classes['sortdirection']));
    }

    public function testVmDoesNotRegisterBuiltinStubEnumsOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        foreach ([
            'padtype',
            'stringtrimmode',
            'memoryusage',
            'exitstatus',
            'parseurl',
            'requestmethod',
            'infoview',
            'connectionstatus',
            'sessionstatus',
            'responsecode',
            'propertyhooktype',
            'phpinputfilter',
            'sockettype',
        ] as $lc) {
            $this->assertFalse(isset($ctx->classes[$lc]), $lc);
        }
    }

    public function testVmDoesNotRegisterJsonValidateOnDefault84DevReference(): void
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

    public function testVmDoesNotRegisterStreamSupportsOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->functions['stream_supports']));
    }

    public function testVmRegistersStreamSupportsOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $runtime = new Runtime();
            $this->assertTrue(isset($runtime->vmContext->functions['stream_supports']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmDoesNotRegisterReadonlyBuiltinOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->functions['readonly']));
    }

    public function testVmDoesNotRegisterArrayFindFamilyOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        foreach (['array_find', 'array_find_key', 'array_any', 'array_any_key', 'array_all', 'array_all_key', 'array_first', 'array_last', 'array_first_key', 'array_last_key'] as $fn) {
            $this->assertFalse(isset($ctx->functions[$fn]), $fn);
        }
    }

    public function testVmWithholdsArrayFirstLastOnProfile84ButRegistersFindFamily(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            foreach (['array_find', 'array_find_key', 'array_any', 'array_all'] as $fn) {
                $this->assertTrue(isset($ctx->functions[$fn]), $fn);
            }
            foreach (['array_first', 'array_last'] as $fn) {
                $this->assertFalse(isset($ctx->functions[$fn]), $fn);
            }
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmRegistersArrayFirstLastOnProfile85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            foreach (['array_first', 'array_last'] as $fn) {
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

    public function testVmDoesNotRegisterGeneratorToArrayOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $runtime = new Runtime();
            $this->assertFalse(isset($runtime->vmContext->functions['generator_to_array']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmDoesNotRegisterGeneratorToArrayOnPhp82Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $runtime = new Runtime();
            $this->assertFalse(isset($runtime->vmContext->functions['generator_to_array']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmDoesNotRegisterDateTimeMicrosecondOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $dt = $runtime->vmContext->classes['datetime'] ?? null;
        $this->assertNotNull($dt);
        $this->assertFalse(isset($dt->methods['getmicrosecond']));
        $this->assertFalse(isset($dt->methods['setmicrosecond']));
        $dti = $runtime->vmContext->classes['datetimeimmutable'] ?? null;
        $this->assertNotNull($dti);
        $this->assertFalse(isset($dti->methods['getmicrosecond']));
        $this->assertFalse(isset($dti->methods['setmicrosecond']));
    }

    public function testVmRegistersDateTimeMicrosecondWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $dt = $runtime->vmContext->classes['datetime'] ?? null;
            $this->assertNotNull($dt);
            $this->assertTrue(isset($dt->methods['getmicrosecond']));
            $this->assertTrue(isset($dt->methods['setmicrosecond']));
            $dti = $runtime->vmContext->classes['datetimeimmutable'] ?? null;
            $this->assertNotNull($dti);
            $this->assertTrue(isset($dti->methods['getmicrosecond']));
            $this->assertTrue(isset($dti->methods['setmicrosecond']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmDoesNotRegisterConvertCyrStringOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->functions['convert_cyr_string']));
    }

    public function testVmDoesNotRegisterStrxfrmOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->functions['strxfrm']));
    }

    public function testVmDoesNotRegisterGetmygrgidOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->functions['getmygrgid']));
    }

    public function testVmDoesNotRegisterDisktotalspaceOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->functions['disktotalspace']));
    }


    public function testVmRegistersCrc32cOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $this->assertTrue(isset($runtime->vmContext->functions['crc32c']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testMbTrimWithheldOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $this->assertFalse(CompilerVersion::supportsMbTrimFunctions());
            $this->assertFalse(CompilerVersion::advertisesMbTrimFunctions());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testMbTrimWithheldOnPhp82Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsMbTrimFunctions());
            $this->assertFalse(CompilerVersion::advertisesMbTrimFunctions());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmDoesNotRegisterMbTrimOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            foreach (['mb_trim', 'mb_ltrim', 'mb_rtrim'] as $fn) {
                $this->assertFalse(isset($ctx->functions[$fn]), $fn);
            }
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testMbUcwordsWithheldOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $this->assertFalse(CompilerVersion::supportsMbUcwords());
            $this->assertFalse(CompilerVersion::advertisesMbUcwords());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testMbUcwordsWithheldOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertFalse(CompilerVersion::supportsMbUcwords());
            $this->assertFalse(CompilerVersion::advertisesMbUcwords());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmDoesNotRegisterMbStrPadOnDefault84DevReference(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->functions['mb_str_pad']));
    }

    public function testVmDoesNotRegisterMbStrPadOn82Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $runtime = new Runtime();
            $this->assertFalse(isset($runtime->vmContext->functions['mb_str_pad']));
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
}
