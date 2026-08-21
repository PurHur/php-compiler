<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplFileObject::fread / fgetc via live __spl_fd (#33332).
 *
 * @see php-src ext/spl/spl_directory.c zim_SplFileObject_fread / zim_SplFileObject_fgetc
 *
 * @group llvm
 * @group aot
 */
final class SplFileObjectFreadFgetc33332AotTest extends TestCase
{
    public function testAotMatchesZendFreadFgetc(): void
    {
        $root = dirname(__DIR__, 2);
        $repro = $root.'/test/repro/splfileobject_fread_fgetc_aot.php';
        $this->assertFileExists($repro);

        $zendCmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($repro).' 2>&1';
        exec($zendCmd, $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $zend = implode("\n", $zendOut)."\n";
        $this->assertStringContainsString("fread='li'", $zend);
        $this->assertStringContainsString("fgetc='n'", $zend);
        $this->assertStringContainsString("fread2='e1\n'", $zend);

        if (!LlvmToolchain::hasLibrary($root)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $bin = sys_get_temp_dir().'/phpc_issue_33332_'.getmypid().'.bin';
        $cache = sys_get_temp_dir().'/phpc_hr_33332_'.getmypid();
        @mkdir($cache, 0777, true);
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR='.escapeshellarg($cache).' '
            .'PHP_COMPILER_HELPER_RUNTIME_O=0 '
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
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $aot = implode("\n", $runOut)."\n";
            $this->assertSame($zend, $aot, "AOT must match Zend\nZend:\n$zend\nAOT:\n$aot");
        } finally {
            chdir($cwd);
            @unlink($bin);
            // Unique cache dir — do not leave NestedJIT debris in build/helper-runtime-cache.
            if (is_dir($cache)) {
                $it = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($cache, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($it as $f) {
                    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
                }
                @rmdir($cache);
            }
        }
    }

    public function testNoNewRuntimeCAndProxies(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileDoesNotExist($root.'/runtime/spl_fileobject_fread.c');
        $helper = (string) file_get_contents($root.'/lib/VM/SplFileObjectJitHelper.php');
        $this->assertStringContainsString('compileFread', $helper);
        $this->assertStringContainsString('compileFgetc', $helper);
        $this->assertStringContainsString('__compiler_fread', $helper);
        $this->assertStringContainsString('__compiler_fgetc', $helper);
        $this->assertStringContainsString('#33332', $helper);
        $call = (string) file_get_contents($root.'/lib/JIT/Call/SplFileObjectMethod.php');
        $this->assertStringContainsString("'fread'", $call);
        $this->assertStringContainsString("'fgetc'", $call);
        $this->assertStringContainsString('#33332', $call);
        $ctx = (string) file_get_contents($root.'/lib/JIT/Context.php');
        $this->assertStringContainsString("'fread'", $ctx);
        $this->assertStringContainsString("'fgetc'", $ctx);
        $this->assertStringContainsString('#33332', $ctx);
        $kernel = (string) file_get_contents($root.'/ext/standard/JitStreamIoKernel.php');
        $this->assertStringContainsString('implementFreadForce', $kernel);
    }
}
