<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplFileInfo getFileInfo/getPathInfo (#33299).
 *
 * @see php-src ext/spl/spl_directory.c zim_SplFileInfo_getFileInfo / getPathInfo
 *
 * @group llvm
 * @group aot
 */
final class SplFileInfoGetFileInfo33299AotTest extends TestCase
{
    public function testContextRegistersProxies(): void
    {
        $ctx = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("'getFileInfo', 'getPathInfo'", $ctx);
    }

    public function testHelperLowersGetFileInfoPathInfo(): void
    {
        $helper = (string) file_get_contents(__DIR__.'/../../lib/VM/DirectoryIteratorJitHelper.php');
        $this->assertStringContainsString('compileGetFileInfo', $helper);
        $this->assertStringContainsString('compileGetPathInfo', $helper);
        $this->assertStringContainsString('emitSplFileInfoPathParts', $helper);
    }

    public function testAotMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/splfileinfo_getfileinfo_aot.php';
        $bin = sys_get_temp_dir().'/phpc_issue_33299_'.getmypid().'.bin';

        $zendOut = [];
        $zendRc = 0;
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $zend = implode("\n", $zendOut)."\n";

        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame($zend, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
