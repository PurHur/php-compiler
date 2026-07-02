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

    public function testJsonValidateAdvertisedOnForwardProfile(): void
    {
        $this->assertTrue(CompilerVersion::supportsJsonValidate());
    }

    public function testMbStrPadWithheldOnReferenceProfileUntilStable84(): void
    {
        $this->assertFalse(CompilerVersion::supportsMbStrPad());
    }

    public function testStrIncrementAdvertisedOnForwardProfile(): void
    {
        $this->assertTrue(CompilerVersion::supportsStrIncrement());
    }

    public function testClassHasFunctionsAdvertisedOnForwardProfile(): void
    {
        $this->assertTrue(CompilerVersion::supportsClassHasFunctions());
    }

    public function testPhp84ReflectionProbeBuiltinsWithheldOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsPhp84ReflectionProbeBuiltins());
    }

    public function testClassUsesRecursiveWithheldOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsClassUsesRecursive());
    }

    public function testFpowAdvertisedOnForwardProfile(): void
    {
        $this->assertTrue(CompilerVersion::supportsFpow());
    }

    public function testNextafterWithheldOnReferenceProfileUntilStable84(): void
    {
        $this->assertFalse(CompilerVersion::supportsNextafter());
    }

    public function testRoundingModeEnumAdvertisedOnForwardProfile(): void
    {
        $this->assertTrue(CompilerVersion::supportsRoundingModeEnum());
    }

    public function testVmRegistersRoundingModeOnForwardProfile(): void
    {
        $runtime = new Runtime();
        $this->assertTrue(isset($runtime->vmContext->classes['roundingmode']));
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
    }

    public function testStreamSupportsWithheldOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsStreamSupports());
    }

    public function testPhp84ArraySearchFunctionsAdvertisedOnForwardProfile(): void
    {
        $this->assertTrue(CompilerVersion::supportsPhp84ArraySearchFunctions());
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

    public function testVmRegistersJsonValidateOnForwardProfile(): void
    {
        $runtime = new Runtime();
        $this->assertTrue(isset($runtime->vmContext->functions['json_validate']));
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

    public function testVmRegistersArrayFindFamilyOnForwardProfile(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        foreach (['array_find', 'array_find_key', 'array_any', 'array_all', 'array_first', 'array_last'] as $fn) {
            $this->assertTrue(isset($ctx->functions[$fn]), $fn);
        }
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

    public function testVmRegistersClosureGetCurrentOnForwardProfile(): void
    {
        $runtime = new Runtime();
        $closure = $runtime->vmContext->classes['closure'] ?? null;
        $this->assertNotNull($closure);
        $this->assertTrue(isset($closure->methods['getcurrent']));
    }
}
