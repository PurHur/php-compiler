<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: DirectoryIterator getRealPath (#33287).
 *
 * @see php-src ext/spl/spl_directory.c zim_SplFileInfo_getRealPath
 *
 * @group llvm
 * @group aot
 */
final class DirectoryIteratorGetRealpath33287AotTest extends TestCase
{
    public function testContextRegistersGetRealPathProxy(): void
    {
        $ctx = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("'getRealPath'", $ctx);
    }

    public function testHelperLowersViaJitRealpath(): void
    {
        $helper = (string) file_get_contents(__DIR__.'/../../lib/VM/DirectoryIteratorJitHelper.php');
        $this->assertStringContainsString('compileGetRealPath', $helper);
        $this->assertStringContainsString('ensureLibcRealpath', $helper);
        $this->assertStringContainsString('realpath', $helper);
    }

    public function testAotMatchesZendGetRealPath(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/directoryiterator_getrealpath_aot_33287.php';
        $bin = sys_get_temp_dir().'/phpc_issue_33287_'.getmypid().'.bin';

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
