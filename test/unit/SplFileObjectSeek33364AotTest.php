<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplFileObject::seek (SeekableIterator line) via live stream (#33364 / #33453).
 *
 * @see php-src ext/spl/spl_directory.c zim_SplFileObject_seek
 *
 * @group llvm
 * @group aot
 */
final class SplFileObjectSeek33364AotTest extends TestCase
{
    public function testAotMatchesZendSeek(): void
    {
        $root = dirname(__DIR__, 2);
        $repro = $root.'/test/repro/splfileobject_seek_aot.php';
        $this->assertFileExists($repro);

        $zendCmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($repro).' 2>&1';
        exec($zendCmd, $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $zend = implode("\n", $zendOut)."\n";
        $this->assertStringContainsString('seek(1) key=1', $zend);
        $this->assertStringContainsString('seek1_cur="b\\n"', $zend);
        $this->assertStringContainsString('seek(4) key=4', $zend);
        $this->assertStringContainsString('seek(10) key=3', $zend);
        $this->assertStringContainsString('seek10_cur=false', $zend);

        if (!LlvmToolchain::hasLibrary($root)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $bin = sys_get_temp_dir().'/phpc_issue_33364_'.getmypid().'.bin';
        $cacheDir = sys_get_temp_dir().'/phpc_helper_cache_33364_'.getmypid();
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
        $this->assertFileDoesNotExist($root.'/runtime/spl_fileobject_seek.c');
        $helper = (string) file_get_contents($root.'/lib/VM/SplFileObjectJitHelper.php');
        $this->assertStringContainsString('compileSeek', $helper);
        $this->assertStringContainsString('#33453', $helper);
        $this->assertStringContainsString('#33364', $helper);
        $call = (string) file_get_contents($root.'/lib/JIT/Call/SplFileObjectMethod.php');
        $this->assertStringContainsString("'seek'", $call);
        $ctx = (string) file_get_contents($root.'/lib/JIT/Context.php');
        $this->assertStringContainsString("'seek'", $ctx);
        $this->assertStringContainsString('#33364', $ctx);
    }
}
