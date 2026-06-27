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

    public function testZendThreadIdAdvertisedOn84DevProfile(): void
    {
        $this->assertTrue(CompilerVersion::supportsZendThreadId());
    }

    public function testJsonValidateAdvertisedOn84DevProfile(): void
    {
        $this->assertTrue(CompilerVersion::supportsJsonValidate());
    }

    public function testMbStrPadWithheldOnReferenceProfileUntilStable84(): void
    {
        $this->assertFalse(CompilerVersion::supportsMbStrPad());
    }

    public function testStrIncrementAdvertisedOn84DevProfile(): void
    {
        $this->assertTrue(CompilerVersion::supportsStrIncrement());
    }

    public function testFpowWithheldOnReferenceProfileUntilStable84(): void
    {
        $this->assertFalse(CompilerVersion::supportsFpow());
    }

    public function testReadonlyBuiltinWithheldOnReferenceProfileUntilStable84(): void
    {
        $this->assertFalse(CompilerVersion::supportsReadonlyBuiltin());
    }

    public function testStreamSupportsWithheldUntil85(): void
    {
        $this->assertFalse(CompilerVersion::supportsStreamSupports());
    }

    public function testPhp84ArraySearchFunctionsAdvertisedOn84DevProfile(): void
    {
        $this->assertTrue(CompilerVersion::supportsPhp84ArraySearchFunctions());
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

    public function testVmRegistersZendThreadIdOn84DevProfile(): void
    {
        $runtime = new Runtime();
        $this->assertTrue(isset($runtime->vmContext->functions['zend_thread_id']));
    }

    public function testVmDoesNotRegisterStreamSupportsUntil85(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->functions['stream_supports']));
    }

    public function testVmDoesNotRegisterReadonlyBuiltinOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->functions['readonly']));
    }

    public function testVmRegistersJsonValidateOn84DevProfile(): void
    {
        $runtime = new Runtime();
        $this->assertTrue(isset($runtime->vmContext->functions['json_validate']));
    }

    public function testVmRegistersArrayFindFamilyOn84DevProfile(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        foreach (['array_find', 'array_find_key', 'array_any', 'array_all', 'array_first', 'array_last'] as $fn) {
            $this->assertTrue(isset($ctx->functions[$fn]), $fn);
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

    public function testVmDoesNotRegisterCrc32cOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->functions['crc32c']));
    }

    public function testVmDoesNotRegisterMbStrPadOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->functions['mb_str_pad']));
    }

    public function testVmRegistersForwardCompatBuiltinAttributeClassesOn84DevProfile(): void
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
