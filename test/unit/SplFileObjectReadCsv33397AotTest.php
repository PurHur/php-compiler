<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplFileObject::READ_CSV makes current/foreach yield CSV arrays (#33397).
 *
 * @see php-src ext/spl/spl_directory.c spl_filesystem_file_read_csv / READ_CSV
 *
 * @group llvm
 * @group aot
 */
final class SplFileObjectReadCsv33397AotTest extends TestCase
{
    public function testZendAndVmMatch(): void
    {
        $root = dirname(__DIR__, 2);
        $repro = $root.'/test/repro/splfileobject_read_csv_aot.php';
        $this->assertFileExists($repro);

        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($repro).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $zend = implode("\n", $zendOut)."\n";
        $this->assertSame("0:[\"1\",\"2\"]|1:[\"3\",\"4\"]|2:[null]\ncurrent=[\"1\",\"2\"]\nfgets=\"1,2\\n\"\n", $zend);

        exec(
            escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($repro).' 2>&1',
            $vmOut,
            $vmRc
        );
        $this->assertSame(0, $vmRc, implode("\n", $vmOut));
        $this->assertSame($zend, implode("\n", $vmOut)."\n");
    }

    public function testAotMatchesZendReadCsv(): void
    {
        $root = dirname(__DIR__, 2);
        $repro = $root.'/test/repro/splfileobject_read_csv_aot.php';
        $this->assertFileExists($repro);

        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($repro).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $zend = implode("\n", $zendOut)."\n";

        if (!LlvmToolchain::hasLibrary($root)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $bin = sys_get_temp_dir().'/phpc_issue_33397_'.getmypid().'.bin';
        $cache = sys_get_temp_dir().'/phpc_hr_33397_'.getmypid();
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

    public function testHelperHonorsReadCsvWithoutNewPropsOrRuntimeC(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileDoesNotExist($root.'/runtime/spl_fileobject_read_csv.c');
        $helper = (string) file_get_contents($root.'/lib/VM/SplFileObjectJitHelper.php');
        $this->assertStringContainsString('FLAG_READ_CSV', $helper);
        $this->assertStringContainsString('#33397', $helper);
        $this->assertStringContainsString('JitStrGetcsv::invoke', $helper);
        $this->assertStringContainsString('honorReadCsv', $helper);
        // No dedicated CSV-cache object property (layout landmine #33397).
        $this->assertStringNotContainsString("'__spl_cur_csv'", $helper);
        $objectPhp = (string) file_get_contents($root.'/lib/JIT/Builtin/Type/Object_.php');
        $this->assertStringNotContainsString('__spl_cur_csv', $objectPhp);
        // fgets must not honor READ_CSV (Zend zim_SplFileObject_fgets).
        $this->assertMatchesRegularExpression(
            '/compileFgets[\s\S]*?emitReadLineToValueBox\(\$context, \$receiver, 1, false\)/',
            $helper
        );
    }
}
