<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Builtin advertisement profile gates (#11842). */
final class CompilerVersionBuiltinAdvertisementTest extends TestCase
{
    public function testBuiltinAdvertisementVersionMatchesZend82ReferenceUntilStable84(): void
    {
        $this->assertSame('8.2.0', CompilerVersion::builtinAdvertisementVersion());
    }

    public function testZendThreadIdNotAdvertisedOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsZendThreadId());
    }

    public function testJsonValidateNotAdvertisedOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsJsonValidate());
    }

    public function testStrIncrementNotAdvertisedOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsStrIncrement());
    }

    public function testFpowNotAdvertisedOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsFpow());
    }

    public function testStreamSupportsNotAdvertisedOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsStreamSupports());
    }

    public function testConvertCyrStringNotAdvertisedOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsConvertCyrString());
    }

    public function testStrxfrmNotAdvertisedOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsStrxfrm());
    }

    public function testVmDoesNotRegisterZendThreadIdOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->functions['zend_thread_id']));
    }

    public function testVmDoesNotRegisterStreamSupportsOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->functions['stream_supports']));
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

    public function testVmDoesNotRegisterForwardCompatBuiltinAttributeClassesOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $this->assertFalse(isset($ctx->classes['override']));
        $this->assertFalse(isset($ctx->classes['deprecated']));
        $this->assertFalse(isset($ctx->classes['nodiscard']));
        $this->assertFalse(isset($ctx->classes['delayedtargetvalidation']));
        $this->assertFalse(isset($ctx->classes['compiletime']));
    }
}
