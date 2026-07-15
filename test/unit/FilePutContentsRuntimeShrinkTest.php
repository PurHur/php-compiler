<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\FilePutContentsJitHelper;
use PHPUnit\Framework\TestCase;

/** __compiler_file_put_contents: PHP helper for embed; ext kernel for user-script AOT (#15310, #19294). */
final class FilePutContentsRuntimeShrinkTest extends TestCase
{
    public function testStringFilePutContentsRoutesDeferThroughExtKernelNotLibcBuiltin(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFilePutContents.php');
        $this->assertStringContainsString('FilePutContentsJitHelper', $bridge);
        $this->assertStringContainsString('JitFilePutContentsKernel', $bridge);
        $this->assertStringContainsString('UserScriptAotDeferNestedJit', $bridge);
        $this->assertStringNotContainsString('StringFilePutContentsLibc', $bridge);
        $this->assertStringNotContainsString("lookupFunction('fopen')", $bridge);
        $this->assertStringNotContainsString("lookupFunction('fwrite')", $bridge);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringFilePutContentsLibc.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitFilePutContentsKernel.php');
    }

    public function testFilePutContentsJitHelperDelegatesToVmFs(): void
    {
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
        $this->assertStringContainsString('JitFilePutContentsKernel.php', $spine);
        $this->assertStringContainsString('StringFilePutContents.php', $spine);
        $this->assertStringNotContainsString('StringFilePutContentsLibc.php', $spine);
    }
}
