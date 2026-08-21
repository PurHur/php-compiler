<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplFileObject::fstat via live stream handle (#33359).
 *
 * @see php-src ext/spl/spl_directory.c zim_SplFileObject_fstat
 *
 * @group llvm
 * @group aot
 */
final class SplFileObjectFstat33359AotTest extends TestCase
{
    public function testAotMatchesZendFstat(): void
    {
        $root = dirname(__DIR__, 2);
        $repro = $root.'/test/repro/splfileobject_fstat_aot.php';
        $this->assertFileExists($repro);

        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($repro).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $zend = implode("\n", $zendOut)."\n";
        $this->assertStringContainsString('size=2', $zend);

        exec(
            escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($repro).' 2>&1',
            $vmOut,
            $vmRc
        );
        $this->assertSame(0, $vmRc, implode("\n", $vmOut));
        $this->assertSame($zend, implode("\n", $vmOut)."\n");

        if (!LlvmToolchain::hasLibrary($root)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $bin = sys_get_temp_dir().'/phpc_issue_33359_'.getmypid().'.bin';
        $cacheDir = sys_get_temp_dir().'/phpc_helper_cache_33359_'.getmypid();
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
        $this->assertFileDoesNotExist($root.'/runtime/spl_fileobject_fstat.c');
        $helper = (string) file_get_contents($root.'/lib/VM/SplFileObjectJitHelper.php');
        $this->assertStringContainsString('compileFstat', $helper);
        $this->assertStringContainsString('#33359', $helper);
        $this->assertStringContainsString('JitFstat::invoke', $helper);
        $call = (string) file_get_contents($root.'/lib/JIT/Call/SplFileObjectMethod.php');
        $this->assertStringContainsString("'fstat'", $call);
        $ctx = (string) file_get_contents($root.'/lib/JIT/Context.php');
        $this->assertStringContainsString("'fstat'", $ctx);
        $kernel = (string) file_get_contents($root.'/ext/standard/JitStreamIoKernel.php');
        $this->assertStringContainsString('implementFstatForce', $kernel);
        $this->assertStringContainsString('fstat_entry', $kernel);
        $read = (string) file_get_contents($root.'/lib/JIT/Builtin/StreamReadRuntime.php');
        $this->assertStringContainsString('implementFstatForce', $read);
    }
}
