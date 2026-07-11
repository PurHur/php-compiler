<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\FilePutContentsJitHelper;
use PHPUnit\Framework\TestCase;

/** __compiler_file_put_contents JIT routes through FilePutContentsJitHelper PHP not libc LLVM (#15310). */
final class FilePutContentsRuntimeShrinkTest extends TestCase
{
    public function testStringFilePutContentsUsesPhpBridgeNotLibcFopen(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFilePutContents.php');
        $this->assertStringContainsString('FilePutContentsJitHelper', $bridge);
        $this->assertStringNotContainsString("lookupFunction('fopen')", $bridge);
        $this->assertStringNotContainsString("lookupFunction('flock')", $bridge);
        $this->assertStringNotContainsString("lookupFunction('fwrite')", $bridge);
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

    public function testSpineBundleIncludesFilePutContentsJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('FilePutContentsJitHelper.php', $spine);
        $this->assertStringContainsString('StringFilePutContents.php', $spine);
    }
}
