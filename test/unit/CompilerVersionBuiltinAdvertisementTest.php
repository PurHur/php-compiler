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
}
