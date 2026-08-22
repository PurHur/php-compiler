<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplObjectStorage::getHash matches spl_object_hash (#33854).
 *
 * @see ext/spl/spl_observer.c zim_SplObjectStorage_getHash
 *
 * @group llvm
 * @group aot
 */
final class SplObjectStorageGetHash33854AotTest extends TestCase
{
    public function testVmMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/aot_spl_object_storage_gethash.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'aot_spl_object_storage_gethash.php'));
        $out = (string) ob_get_clean();
        $this->assertSame("same\ndistinct\n", $out);
    }

    public function testAotMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_spl_object_storage_gethash.php';
        $bin = sys_get_temp_dir().'/phpc_issue_33854_gethash_'.getmypid().'.bin';
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
            $this->assertSame("same\ndistinct\n", implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
