<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplFileObject::READ_CSV without NestedJIT segfault (#33397 / after #33448/#33451).
 *
 * Locks pure-LLVM iterator parse (JitExplode + null row) and CUR_LINE cache
 * (never CSV row in construct `__spl_ht`).
 *
 * @see php-src ext/spl/spl_directory.c spl_filesystem_file_read_csv / SPL_FILE_OBJECT_READ_CSV
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
        $this->assertStringContainsString('0:["1","2"]', $zend);
        $this->assertStringContainsString('cur:["1","2"]', $zend);
        $this->assertStringContainsString('fgets:"1,2\n"', $zend);
        $this->assertStringContainsString('[NULL]', $zend);

        exec(
            escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($repro).' 2>&1',
            $vmOut,
            $vmRc
        );
        $this->assertSame(0, $vmRc, implode("\n", $vmOut));
        $this->assertSame($zend, implode("\n", $vmOut)."\n");
    }

    public function testAotMatchesZendWithoutSegfault(): void
    {
        $root = dirname(__DIR__, 2);
        $repro = $root.'/test/repro/splfileobject_read_csv_aot.php';

        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($repro).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $zend = implode("\n", $zendOut)."\n";

        if (!LlvmToolchain::hasLibrary($root)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $id = uniqid('33397_', true);
        $bin = sys_get_temp_dir().'/phpc_issue_'.$id.'.bin';
        $cache = sys_get_temp_dir().'/phpc_hr_'.$id;
        @mkdir($cache, 0777, true);
        // Unique helper cache — shared nests segfault under phpunit (peer #33346).
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR='.escapeshellarg($cache).' '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($repro).' 2>&1';
        $cwd = getcwd();
        chdir($root);
        $script = '';
        $zendFile = '';
        try {
            exec($compile, $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, implode("\n", $compileOut));
            $this->assertFileExists($bin);
            // Bash loop: PHP exec() of the same AOT binary is intermittently SIGSEGV on
            // this host; docker-exec bash stress matches (#33397).
            $script = sys_get_temp_dir().'/phpc_run_'.$id.'.sh';
            $zendFile = sys_get_temp_dir().'/phpc_zend_'.$id.'.txt';
            file_put_contents($zendFile, $zend);
            file_put_contents(
                $script,
                "#!/bin/bash\nset -euo pipefail\n"
                ."zend=\$(cat ".escapeshellarg($zendFile).")\n"
                ."for i in \$(seq 1 20); do\n"
                ."  out=\$(".escapeshellarg($bin)." 2>&1) || { echo \"AOT run \$i rc=\$?\"; echo \"\$out\"; exit 1; }\n"
                ."  if [ \"\$out\" != \"\$zend\" ]; then echo \"AOT run \$i mismatch\"; echo \"\$out\"; exit 1; fi\n"
                ."done\n"
            );
            exec('bash '.escapeshellarg($script).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
        } finally {
            chdir($cwd);
            @unlink($bin);
            @unlink($script);
            @unlink($zendFile);
            foreach (glob($cache.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($cache);
        }
    }

    public function testIteratorUsesExplodeNotNestedJitStrGetcsv(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileDoesNotExist($root.'/runtime/spl_fileobject_read_csv.c');
        $helper = (string) file_get_contents($root.'/lib/VM/SplFileObjectJitHelper.php');
        $this->assertStringContainsString('FLAG_READ_CSV', $helper);
        $this->assertStringContainsString('#33397', $helper);
        $this->assertStringContainsString('emitReadCsvLineToValueBox', $helper);
        $this->assertStringContainsString('emitCsvFieldsValueBox', $helper);
        $this->assertStringContainsString('JitExplode::explode', $helper);
        $this->assertStringNotContainsString('JitStrGetcsv::invoke', $helper);
        $this->assertStringContainsString('Does not write PROP_HT', $helper);
        $this->assertDoesNotMatchRegularExpression(
            '/emitReadCsvLineToValueBox[\s\S]*?storeHashtableProp\(\$context, \$obj, self::PROP_HT/',
            $helper
        );
        $this->assertStringNotContainsString(
            'storeHashtableProp($context, $obj, self::PROP_HT',
            $helper
        );
        $outer = (string) file_get_contents($root.'/lib/VM/SplOuterIteratorHt.php');
        $this->assertStringNotContainsString("'splfileobject'", $outer);
    }
}
