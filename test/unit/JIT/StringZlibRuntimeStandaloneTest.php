<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/**
 * gz* AOT must not revive hand-written zlib_compress.c (#6791, #13347).
 * Thin libz LLVM in StringZlibJit is the AOT path (#26864); VM SSOT stays VmZlibCore.
 *
 * @group aot-lint
 */
final class StringZlibRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeShrinkRemovesZlibCompressC(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/zlib_compress.c');

        $linker = (string) file_get_contents(__DIR__.'/../../../lib/AOT/Linker.php');
        $this->assertStringNotContainsString('zlib_compress.c', $linker);

        $runtime = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringZlib.php');
        $this->assertStringContainsString('ZlibRuntime', $runtime);
        $this->assertStringNotContainsString('zlib_compress.c', $runtime);

        $zlibRuntime = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/ZlibRuntime.php');
        $this->assertStringContainsString('StringZlibJit::implement', $zlibRuntime);
        $this->assertStringContainsString('VmZlibCore', $zlibRuntime);
        $this->assertFileExists(__DIR__.'/../../../lib/JIT/Builtin/StringZlibJit.php');
    }
}
