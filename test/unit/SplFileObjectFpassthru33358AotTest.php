<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplFileObject::fpassthru via live stream handle (#33358).
 *
 * @see php-src ext/spl/spl_directory.c zim_SplFileObject_fpassthru
 *
 * @group llvm
 * @group aot
 */
final class SplFileObjectFpassthru33358AotTest extends TestCase
{
    public function testAotMatchesZendFpassthru(): void
    {
        $root = dirname(__DIR__, 2);
        $repro = $root.'/test/repro/splfileobject_fpassthru_aot.php';
        $this->assertFileExists($repro);

        $zendCmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($repro).' 2>&1';
        exec($zendCmd, $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $zend = implode("\n", $zendOut)."\n";
        $this->assertStringContainsString("BEGIN\nhiEND n=2\n", $zend);

        if (!LlvmToolchain::hasLibrary($root)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $bin = sys_get_temp_dir().'/phpc_issue_33358_'.getmypid().'.bin';
        // Unique helper cache — shared HELPER_RUNTIME_O=0 nests segfault under phpunit (#33348).
        $cache = sys_get_temp_dir().'/phpc_hr_33358_'.getmypid();
        @mkdir($cache, 0777, true);
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR='.escapeshellarg($cache).' '
            .escapeshellarg(PHP_BINARY).' '
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
            foreach (glob($cache.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($cache);
        }
    }

    public function testProxiesAndNoNewRuntimeC(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileDoesNotExist($root.'/runtime/spl_fileobject_fpassthru.c');
        $helper = (string) file_get_contents($root.'/lib/VM/SplFileObjectJitHelper.php');
        $this->assertStringContainsString('compileFpassthru', $helper);
        $this->assertStringContainsString('#33358', $helper);
        $this->assertStringContainsString('JitFpassthru::invoke', $helper);
        $call = (string) file_get_contents($root.'/lib/JIT/Call/SplFileObjectMethod.php');
        $this->assertStringContainsString("'fpassthru'", $call);
        $ctx = (string) file_get_contents($root.'/lib/JIT/Context.php');
        $this->assertStringContainsString("'fpassthru'", $ctx);
        $this->assertStringContainsString('#33358', $ctx);
    }
}
