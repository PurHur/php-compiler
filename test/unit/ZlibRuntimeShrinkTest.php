<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * zlib JIT/AOT: thin libz for one-shot gz* (#26864); NestedJIT only for coding-type probe.
 *
 * VM SSOT remains VmZlibCore pure PHP. Do not NestedJIT the sdefl corpus under thin AOT.
 */
final class ZlibRuntimeShrinkTest extends TestCase
{
    public function testStringZlibRoutesThroughRuntimeAndLibzJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringZlib.php');
        $this->assertStringContainsString('ZlibRuntime', $source);

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ZlibRuntime.php');
        $this->assertStringContainsString('StringZlibJit::implement', $runtime);
        $this->assertStringContainsString('VmZlibCore', $runtime);
        $this->assertStringContainsString('ensureGetCodingTypeLinked', $runtime);
        $this->assertStringContainsString('getCodingTypeArgv', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $runtime);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $runtime);
        $this->assertStringNotContainsString('parseAndCompile', $runtime);
        $this->assertStringNotContainsString('new JIT(', $runtime);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $runtime);

        $libz = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringZlibJit.php');
        $this->assertStringContainsString('compress2', $libz);
        $this->assertStringContainsString('deflateInit2_', $libz);
        $this->assertStringContainsString('#26864', $libz);

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

    public function testStringZlibHasNoLibzDlopen(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringZlib.php');
        $this->assertStringNotContainsString('preloadLibz', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('dlopen', $source);

        $gzStream = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GzStreamRuntime.php');
        $this->assertStringNotContainsString('preloadLibz', $gzStream);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/NativeDlopen.php');
    }
}
