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

    public function testBuiltinStubEnumsWithheldOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsBuiltinStubEnums());
    }

    public function testJsonValidateWithheldOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsJsonValidate());
    }

    public function testJsonValidateAdvertisedOnForwardProfile(): void
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

    public function testMbStrPadWithheldOnReferenceProfileUntilStable84(): void
    {
        $this->assertFalse(CompilerVersion::supportsMbStrPad());
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
        $this->assertFalse(CompilerVersion::supportsPhp84ReflectionProbeBuiltins());
    }

    public function testReflectionCreateFromFactoriesWithheldOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsReflectionCreateFromFactories());
    }

    public function testClassUsesRecursiveWithheldOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsClassUsesRecursive());
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

    public function testStreamSupportsAdvertisedOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
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

    public function testVmRegistersFpowFamilyOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            foreach (['fpow', 'fmin', 'fmax', 'stream_supports', 'attribute_exists'] as $fn) {
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
        foreach (['fpow', 'fmin', 'fmax'] as $fn) {
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

    public function testGeneratorToArrayWithheldOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsGeneratorToArray());
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
            $this->assertTrue(CompilerVersion::supportsGeneratorToArray());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testDateTimeMicrosecondWithheldOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsDateTimeMicrosecond());
    }

    public function testConvertCyrStringNotAdvertisedOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsConvertCyrString());
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
    }

    public function testCrc32cNotAdvertisedOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsCrc32c());
    }

    public function testVmDoesNotRegisterZendThreadIdOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->functions['zend_thread_id']));
    }

    public function testVmDoesNotRegisterClassUsesRecursiveOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->functions['class_uses_recursive']));
    }

    public function testVmDoesNotRegisterPhp84ReflectionProbeBuiltinsOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        foreach (['attribute_exists', 'class_meth_exists', 'unitenum_exists'] as $fn) {
            $this->assertFalse(isset($ctx->functions[$fn]), $fn);
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

    public function testVmDoesNotRegisterStreamSupportsOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->functions['stream_supports']));
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

    public function testVmDoesNotRegisterGeneratorToArrayOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->functions['generator_to_array']));
    }

    public function testVmDoesNotRegisterDateTimeMicrosecondOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $dt = $runtime->vmContext->classes['datetime'] ?? null;
        $this->assertNotNull($dt);
        $this->assertFalse(isset($dt->methods['getmicrosecond']));
        $this->assertFalse(isset($dt->methods['setmicrosecond']));
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

    public function testVmDoesNotRegisterCrc32cOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->functions['crc32c']));
    }

    public function testMbTrimWithheldOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsMbTrimFunctions());
    }

    public function testVmDoesNotRegisterMbStrPadOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->functions['mb_str_pad']));
    }

    public function testVmDoesNotRegisterClosureGetCurrentOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $closure = $runtime->vmContext->classes['closure'] ?? null;
        $this->assertNotNull($closure);
        $this->assertFalse(isset($closure->methods['getcurrent']));
    }
}
