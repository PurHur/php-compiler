<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplFileObject::fgetcsv via live stream handle (#33346).
 *
 * @see php-src ext/spl/spl_directory.c zim_SplFileObject_fgetcsv
 *
 * @group llvm
 * @group aot
 */
final class SplFileObjectFgetcsv33346AotTest extends TestCase
{
    public function testAotMatchesZendFgetcsv(): void
    {
        $root = dirname(__DIR__, 2);
        $repro = $root.'/test/repro/splfileobject_fgetcsv_aot.php';
        $this->assertFileExists($repro);

        $zendCmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($repro).' 2>&1';
        exec($zendCmd, $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $zend = implode("\n", $zendOut)."\n";
        $this->assertStringContainsString("'a'", $zend);
        $this->assertStringContainsString("'b,c'", $zend);

        if (!LlvmToolchain::hasLibrary($root)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $bin = sys_get_temp_dir().'/phpc_issue_33346_'.getmypid().'.bin';
        $cache = sys_get_temp_dir().'/phpc_issue_33346_cache_'.getmypid();
        @mkdir($cache, 0777, true);
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR='
            .escapeshellarg($cache).' '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($repro).' 2>&1';
        $cwd = getcwd();
        chdir($root);
        try {
            exec($compile, $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, implode("\n", $compileOut));
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut)."\ncompile:\n".implode("\n", $compileOut));
            $aot = implode("\n", $runOut)."\n";
            $this->assertSame($zend, $aot, "AOT must match Zend\nZend:\n$zend\nAOT:\n$aot");
        } finally {
            chdir($cwd);
            @unlink($bin);
        }
    }

    public function testProxiesAndNoNewRuntimeC(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileDoesNotExist($root.'/runtime/spl_fileobject_fgetcsv.c');
        $helper = (string) file_get_contents($root.'/lib/VM/SplFileObjectJitHelper.php');
        $this->assertStringContainsString('compileFgetcsv', $helper);
        $this->assertStringContainsString('#33346', $helper);
        $this->assertStringContainsString('JitFgetcsv::invoke', $helper);
        $call = (string) file_get_contents($root.'/lib/JIT/Call/SplFileObjectMethod.php');
        $this->assertStringContainsString("'fgetcsv'", $call);
        $ctx = (string) file_get_contents($root.'/lib/JIT/Context.php');
        $this->assertStringContainsString("'fputcsv', 'fgetcsv', 'eof'", $ctx);
        $this->assertStringContainsString('#33346', $ctx);
    }
}
