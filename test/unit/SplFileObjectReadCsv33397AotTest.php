<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplFileObject::READ_CSV without NestedJIT segfault (#33397 / reopen after #33448).
 *
 * @group llvm
 * @group aot
 */
final class SplFileObjectReadCsv33397AotTest extends TestCase
{
    public function testAotMatchesZendWithoutSegfault(): void
    {
        $root = dirname(__DIR__, 2);
        $repro = $root.'/test/repro/splfileobject_read_csv_aot.php';
        $this->assertFileExists($repro);

        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($repro).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $zend = implode("\n", $zendOut)."\n";
        $this->assertStringContainsString('[NULL]', $zend);
        $this->assertStringContainsString('"1","2"', $zend);

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
        try {
            exec($compile, $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, implode("\n", $compileOut));
            $this->assertFileExists($bin);
            // Drive repeats via bash (peer AOT tests: PHP exec() of the same binary is
            // intermittently SIGSEGV on this host; bash loop matches docker-exec stress).
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
            @unlink($script ?? '');
            @unlink($zendFile ?? '');
            foreach (glob($cache.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($cache);
            @unlink($root.'/tmp_from_phpunit_33397.bin');
        }
    }

    public function testIteratorUsesExplodeNotNestedJitStrGetcsv(): void
    {
        $root = dirname(__DIR__, 2);
        $helper = (string) file_get_contents($root.'/lib/VM/SplFileObjectJitHelper.php');
        $this->assertStringContainsString('FLAG_READ_CSV', $helper);
        $this->assertStringContainsString('emitCsvFieldsValueBox', $helper);
        $this->assertStringContainsString('JitExplode::explode', $helper);
        $this->assertStringNotContainsString('JitStrGetcsv::invoke', $helper);
        $this->assertStringNotContainsString(
            'storeHashtableProp($context, $obj, self::PROP_HT',
            $helper
        );
    }
}
