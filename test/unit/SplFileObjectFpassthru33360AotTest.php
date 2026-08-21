<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplFileObject::fpassthru via live stream handle (#33360).
 *
 * @see php-src ext/spl/spl_directory.c zim_SplFileObject_fpassthru
 *
 * @group llvm
 * @group aot
 */
final class SplFileObjectFpassthru33360AotTest extends TestCase
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
        $this->assertStringContainsString('hi', $zend);
        $this->assertStringContainsString('n=2', $zend);

        if (!LlvmToolchain::hasLibrary($root)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $bin = sys_get_temp_dir().'/phpc_issue_33360_'.getmypid().'.bin';
        $cacheDir = sys_get_temp_dir().'/phpc_helper_cache_33360_'.getmypid();
        @mkdir($cacheDir, 0777, true);
        $prevCache = getenv('PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR');
        putenv('PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR='.$cacheDir);
        $_ENV['PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR'] = $cacheDir;
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR='
            .escapeshellarg($cacheDir).' '
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
            if (false === $prevCache) {
                putenv('PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR');
                unset($_ENV['PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR']);
            } else {
                putenv('PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR='.$prevCache);
                $_ENV['PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR'] = $prevCache;
            }
            if (is_dir($cacheDir)) {
                foreach (glob($cacheDir.'/*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($cacheDir);
            }
        }
    }

    public function testProxiesAndNoNewRuntimeC(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileDoesNotExist($root.'/runtime/spl_fileobject_fpassthru.c');
        $helper = (string) file_get_contents($root.'/lib/VM/SplFileObjectJitHelper.php');
        $this->assertStringContainsString('compileFpassthru', $helper);
        $this->assertStringContainsString('#33360', $helper);
        $this->assertStringContainsString('JitFpassthru::invoke', $helper);
        $call = (string) file_get_contents($root.'/lib/JIT/Call/SplFileObjectMethod.php');
        $this->assertStringContainsString("'fpassthru'", $call);
        $ctx = (string) file_get_contents($root.'/lib/JIT/Context.php');
        $this->assertStringContainsString("'fpassthru'", $ctx);
        $this->assertStringContainsString('#33360', $ctx);
    }
}
