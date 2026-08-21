<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplFileObject::eof after last successful fgets (#33555).
 *
 * @group llvm
 * @group aot
 */
final class SplTempFileObjectEof33555AotTest extends TestCase
{
    public function testEofAfterLastLineMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_33555_spltemp_eof_after_last_fgets_aot.php');
    }

    public function testEofMidFileStillFalse(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_33555_spltemp_eof_mid_file_aot.php');
    }

    public function testHelperRefreshesAtEofFromFeof(): void
    {
        $root = dirname(__DIR__, 2);
        $helper = (string) file_get_contents($root.'/lib/VM/SplFileObjectJitHelper.php');
        $this->assertStringContainsString('storeAtEofFromStreamFeof', $helper);
        $this->assertStringContainsString('#33555', $helper);
        $this->assertStringContainsString('__compiler_feof', $helper);
        $io = (string) file_get_contents($root.'/ext/standard/JitStreamIoKernel.php');
        $this->assertStringContainsString('implementFeofForce', $io);
        $this->assertStringContainsString('feof_entry', $io);
        $readRt = (string) file_get_contents($root.'/lib/JIT/Builtin/StreamReadRuntime.php');
        $this->assertStringContainsString('implementFeofForce', $readRt);
    }

    private function assertAotMatchesZend(string $src): void
    {
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
    }

    private function runPhp(string $src): string
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/spl_eof_33555_'.getmypid().'_'.md5($src);
        $cmd = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $compOut, $compRc);
            $this->assertSame(0, $compRc, implode("\n", $compOut));
            $this->assertFileExists($bin);
            exec(escapeshellarg($bin).' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            chdir($cwd);
            @unlink($bin);
        }
    }
}
