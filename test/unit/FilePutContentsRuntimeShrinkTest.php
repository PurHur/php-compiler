<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\FilePutContentsJitHelper;
use PHPUnit\Framework\TestCase;

/** __compiler_file_put_contents: no UserScriptAotDeferNestedJit (#15310, #19966). */
final class FilePutContentsRuntimeShrinkTest extends TestCase
{
    public function testStringFilePutContentsHasNoUserScriptDeferKernel(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFilePutContents.php');
        $this->assertStringContainsString('FilePutContentsJitHelper', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
        $this->assertStringNotContainsString('JitFilePutContentsKernel', $bridge);
        $this->assertStringNotContainsString('StringFilePutContentsLibc', $bridge);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringFilePutContentsLibc.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitFilePutContentsKernel.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitFilePutContentsLibc.php');
    }

    public function testFilePutContentsJitHelperUsesPhpcKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/FilePutContentsJitHelper.php');
        $this->assertMatchesRegularExpression('/return\s+\\\\phpc_file_put_contents_kernel\s*\(/', $source);
        $this->assertFileExists(__DIR__.'/../../ext/standard/phpc_file_put_contents_kernel.php');
        $kernel = (string) file_get_contents(__DIR__.'/../../ext/standard/phpc_file_put_contents_kernel.php');
        // NestedJIT flags are __value__ boxes — must use JitLongArg, not trunc(loadValue) (#20266).
        $this->assertStringContainsString('JitLongArg::lower', $kernel);
        $this->assertStringNotContainsString('truncOrBitCast', $kernel);
    }

    public function testFilePutContentsJitHelperWritesViaKernel(): void
    {
        if (!\function_exists('phpc_file_put_contents_kernel')) {
            $this->markTestSkipped('phpc_file_put_contents_kernel requires compiler runtime');
        }
        $path = tempnam(sys_get_temp_dir(), 'phpc-fpc-');
        $this->assertNotFalse($path);
        $written = FilePutContentsJitHelper::writePathArgv($path, 'put-ok', 0);
        $this->assertSame(6, $written);
        $this->assertSame('put-ok', file_get_contents($path));
        $this->assertSame(-1, FilePutContentsJitHelper::writePathArgv($path.'/missing-15310', 'x', 0));
        @unlink($path);
    }

    public function testSpineBundleIncludesFilePutContentsPhpPath(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('FilePutContentsJitHelper.php', $spine);
        $this->assertStringContainsString('StringFilePutContents.php', $spine);
        $this->assertStringContainsString('phpc_file_put_contents_kernel.php', $spine);
        $this->assertStringContainsString('JitFilePutContentsLibc.php', $spine);
        $this->assertStringNotContainsString('JitFilePutContentsKernel.php', $spine);
    }
}
