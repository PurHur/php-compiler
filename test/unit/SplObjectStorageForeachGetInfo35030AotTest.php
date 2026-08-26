<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplObjectStorage foreach syncs __spl_iter_pos for getInfo (#35030).
 *
 * @see ext/spl/spl_observer.c spl_object_storage_get_info / iterator handlers
 *
 * @group llvm
 * @group aot
 */
final class SplObjectStorageForeachGetInfo35030AotTest extends TestCase
{
    public function testVmMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/splobjectstorage_foreach_getinfo_35030.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'splobjectstorage_foreach_getinfo_35030.php'));
        $out = (string) ob_get_clean();
        $this->assertSame("a\nb\n", $out);
    }

    public function testAotMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/splobjectstorage_foreach_getinfo_35030.php';
        $bin = sys_get_temp_dir().'/phpc_issue_35030_sos_'.getmypid().'.bin';
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
            $this->assertSame("a\nb\n", implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
