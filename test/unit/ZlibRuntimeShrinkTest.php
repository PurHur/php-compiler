<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** zlib JIT lowering routes through ZlibJitHelper PHP, not StringZlibJit LLVM (#9879). */
final class ZlibRuntimeShrinkTest extends TestCase
{
    public function testStringZlibRoutesThroughRuntimeNotJitMonolith(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringZlib.php');
        $this->assertStringContainsString('ZlibRuntime', $source);
        $this->assertStringNotContainsString('StringZlibJit::implement', $source);

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ZlibRuntime.php');
        $this->assertStringContainsString('ZlibJitHelper', $runtime);
        $this->assertStringContainsString('VmZlibCore', $runtime);
        $this->assertStringNotContainsString('deflateInit2_', $runtime);

        $this->assertFileExists(__DIR__.'/../../ext/standard/ZlibJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../lib/JIT/Builtin/StringZlibJit.php');
    }

    public function testZlibJitHelperDelegatesToVmZlibCore(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ZlibJitHelper.php');
        $this->assertStringContainsString('VmZlibCore::gzcompress', $source);
        $this->assertStringContainsString('VmZlibCore::gzencode', $source);
        $this->assertStringContainsString('VmZlibCore::zlib_encode', $source);
        $this->assertStringContainsString('VmZlibCore::zlib_decode', $source);
    }
}
