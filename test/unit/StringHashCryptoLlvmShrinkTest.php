<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** User-script hash crypto routes through HashCryptoJitHelper PHP not StringHashCryptoLlvm (#19074). */
final class StringHashCryptoLlvmShrinkTest extends TestCase
{
    public function testStringHashCryptoLlvmDeleted(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringHashCryptoLlvm.php');
    }

    public function testStringHashCryptoJitDeferredUsesPhpBridge(): void
    {
        $jit = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringHashCryptoJit.php');
        $this->assertStringContainsString('StringHashCryptoPhp::implement', $jit);
        $this->assertStringNotContainsString('StringHashCryptoLlvm', $jit);
    }

    public function testStringHashCryptoPhpUsesJitVmHelperLink(): void
    {
        $php = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringHashCryptoPhp.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $php);
        $this->assertStringNotContainsString('NestedJitCompileScope', $php);
        $this->assertStringContainsString('HashCryptoJitHelper', $php);
    }
}
