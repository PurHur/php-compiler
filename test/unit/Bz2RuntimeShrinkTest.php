<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** bz2 JIT: always JitVmHelperLink + Bz2JitHelper; no UserScriptAotDeferNestedJit (#8868, #20117). */
final class Bz2RuntimeShrinkTest extends TestCase
{
    public function testStringBz2RoutesThroughRuntimeNotJitMonolith(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringBz2.php');
        $this->assertStringContainsString('Bz2Runtime', $source);
        $this->assertStringNotContainsString('StringBz2Jit', $source);
        $this->assertStringNotContainsString('preloadLibbz2', $source);

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Bz2Runtime.php');
        $this->assertStringContainsString('Bz2JitHelper', $runtime);
        $this->assertStringContainsString('VmBz2Native', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $runtime);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $runtime);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $runtime);
        $this->assertStringNotContainsString('shouldDefer', $runtime);
        $this->assertStringNotContainsString('Bz2StandaloneLlvm', $runtime);
        $this->assertStringContainsString('VmBz2Core', (string) file_get_contents(__DIR__.'/../../ext/bz2/VmBz2Native.php'));
        $this->assertFileExists(__DIR__.'/../../ext/bz2/Bz2ExtensionPolicy.php');
        $this->assertStringNotContainsString('BZ2_bzBuffToBuffCompress', $runtime);

        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringBz2Jit.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/Bz2StandaloneLlvm.php');
        $this->assertFileExists(__DIR__.'/../../ext/bz2/Bz2JitHelper.php');
    }

    public function testSupportsBz2WithheldOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsBz2());
        $this->assertTrue(\PHPCompiler\ext\bz2\VmBz2Core::available());
        $this->assertFalse(\PHPCompiler\ext\bz2\Bz2ExtensionPolicy::advertisesExtension());
    }

    public function testSupportsBz2TrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsBz2());
            $this->assertTrue(\PHPCompiler\ext\bz2\Bz2ExtensionPolicy::advertisesExtension());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testBz2JitHelperDelegatesToVmBz2Native(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/bz2/Bz2JitHelper.php');
        $this->assertStringContainsString('VmBz2Native::compress', $source);
        $this->assertStringContainsString('VmBz2Native::decompress', $source);
    }
}
