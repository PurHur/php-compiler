<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** bz2 JIT lowering routes through Bz2JitHelper PHP, not StringBz2Jit LLVM (#8868). */
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
        $this->assertStringContainsString('VmBz2Core', (string) file_get_contents(__DIR__.'/../../ext/bz2/VmBz2Native.php'));
        $this->assertStringNotContainsString('BZ2_bzBuffToBuffCompress', $runtime);

        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringBz2Jit.php');
        $this->assertFileExists(__DIR__.'/../../ext/bz2/Bz2JitHelper.php');
    }

    public function testBz2JitHelperDelegatesToVmBz2Native(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/bz2/Bz2JitHelper.php');
        $this->assertStringContainsString('VmBz2Native::compress', $source);
        $this->assertStringContainsString('VmBz2Native::decompress', $source);
    }
}
