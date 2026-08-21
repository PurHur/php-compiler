<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplFileObject::fputcsv via live stream handle (#33340).
 *
 * @see php-src ext/spl/spl_directory.c zim_SplFileObject_fputcsv
 *
 * @group llvm
 * @group aot
 */
final class SplFileObjectFputcsv33340AotTest extends TestCase
{
    public function testAotMatchesZendFputcsv(): void
    {
        $root = dirname(__DIR__, 2);
        $repro = $root.'/test/repro/splfileobject_fputcsv_aot.php';
        $this->assertFileExists($repro);

        $zendCmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($repro).' 2>&1';
        exec($zendCmd, $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $zend = implode("\n", $zendOut)."\n";
        $this->assertStringContainsString('n=4', $zend);
        $this->assertStringContainsString('line=a,b', $zend);

        if (!LlvmToolchain::hasLibrary($root)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $bin = sys_get_temp_dir().'/phpc_issue_33340_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($repro).' 2>&1';
        $cwd = getcwd();
        chdir($root);
        try {
            exec($compile, $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, implode("\n", $compileOut));
            $runs = [];
            for ($i = 0; $i < 5; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, "AOT run $i:\n".implode("\n", $runOut));
                $runs[] = implode("\n", $runOut)."\n";
            }
            foreach ($runs as $i => $aot) {
                $this->assertSame($zend, $aot, "AOT run $i must match Zend");
            }
        } finally {
            chdir($cwd);
            @unlink($bin);
        }
    }

    public function testProxiesAndNoNewRuntimeC(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileDoesNotExist($root.'/runtime/spl_fileobject_fputcsv.c');
        $helper = (string) file_get_contents($root.'/lib/VM/SplFileObjectJitHelper.php');
        $this->assertStringContainsString('compileFputcsv', $helper);
        $this->assertStringContainsString('#33340', $helper);
        $this->assertStringContainsString('JitFputcsv::invoke', $helper);
        $call = (string) file_get_contents($root.'/lib/JIT/Call/SplFileObjectMethod.php');
        $this->assertStringContainsString("'fputcsv'", $call);
        $ctx = (string) file_get_contents($root.'/lib/JIT/Context.php');
        $this->assertStringContainsString("'fputcsv'", $ctx);
        $this->assertStringContainsString('#33340', $ctx);
    }
}
