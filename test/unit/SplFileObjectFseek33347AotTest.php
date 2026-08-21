<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplFileObject::fseek via live stream handle (#33347).
 *
 * @see php-src ext/spl/spl_directory.c zim_SplFileObject_fseek
 *
 * @group llvm
 * @group aot
 */
final class SplFileObjectFseek33347AotTest extends TestCase
{
    public function testAotMatchesZendFseek(): void
    {
        $root = dirname(__DIR__, 2);
        $repro = $root.'/test/repro/splfileobject_fseek_aot.php';
        $this->assertFileExists($repro);

        $zendCmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($repro).' 2>&1';
        exec($zendCmd, $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $zend = implode("\n", $zendOut)."\n";
        $this->assertStringContainsString('rc=0', $zend);
        $this->assertStringContainsString('pos=3', $zend);
        $this->assertStringContainsString("ch='d'", $zend);

        if (!LlvmToolchain::hasLibrary($root)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $bin = sys_get_temp_dir().'/phpc_issue_33347_'.getmypid().'.bin';
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
        $this->assertFileDoesNotExist($root.'/runtime/spl_fileobject_fseek.c');
        $helper = (string) file_get_contents($root.'/lib/VM/SplFileObjectJitHelper.php');
        $this->assertStringContainsString('compileFseek', $helper);
        $this->assertStringContainsString('#33347', $helper);
        $this->assertStringContainsString('JitFseek::invoke', $helper);
        $call = (string) file_get_contents($root.'/lib/JIT/Call/SplFileObjectMethod.php');
        $this->assertStringContainsString("'fseek'", $call);
        $ctx = (string) file_get_contents($root.'/lib/JIT/Context.php');
        $this->assertStringContainsString("'fseek'", $ctx);
        $this->assertStringContainsString('#33347', $ctx);
    }
}
