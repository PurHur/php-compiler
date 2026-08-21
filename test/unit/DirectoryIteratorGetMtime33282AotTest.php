<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: DirectoryIterator getMTime/getPerms/getInode/getOwner/getGroup (#33282).
 *
 * @see php-src ext/spl/spl_directory.c zim_SplFileInfo_getMTime
 *
 * @group llvm
 * @group aot
 */
final class DirectoryIteratorGetMtime33282AotTest extends TestCase
{
    public function testContextRegistersStatMetadataProxies(): void
    {
        $ctx = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        foreach (['getMTime', 'getATime', 'getCTime', 'getPerms', 'getInode', 'getOwner', 'getGroup'] as $m) {
            $this->assertStringContainsString("'".$m."'", $ctx);
        }
    }

    public function testHelperLowersStatFieldsViaJitStat(): void
    {
        $helper = (string) file_get_contents(__DIR__.'/../../lib/VM/DirectoryIteratorJitHelper.php');
        $this->assertStringContainsString('compileGetMTime', $helper);
        $this->assertStringContainsString('compileStatLongField', $helper);
        $this->assertStringContainsString('pathFileMtimeBoxed', $helper);
        $this->assertStringContainsString('pathFilePermsBoxed', $helper);
    }

    public function testAotMatchesZendStatMetadata(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/directoryiterator_getmtime_stat_aot_33282.php';
        $bin = sys_get_temp_dir().'/phpc_issue_33282_'.getmypid().'.bin';

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
            $aot = implode("\n", $runOut)."\n";
            $this->assertSame($zend, $aot);
        } finally {
            @unlink($bin);
        }
    }
}
