<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: var_export()/print_r() on objects must not SIGABRT (#34506).
 *
 * Thin bridges aborted on TYPE_OBJECT after arrays (#34497). Fix: late-emitted
 * VarExportObjectLlvm / PrintRObjectLlvm (get_object_vars + compact array /
 * ClassName Object layout).
 *
 * @see php-src ext/standard/var.c php_var_export_ex / zend_print_zval_r
 *
 * @group llvm
 * @group aot
 */
final class VarExportPrintRObject34506AotTest extends TestCase
{
    private const EXPECTED = "VE empty:\n"
        ."(object) array(\n)\n"
        ."---\n"
        ."VE cast:\n"
        ."(object) array(\n"
        ."   'a' => 1,\n"
        ."   'b' => 'x',\n"
        .")\n"
        ."---\n"
        ."PR empty:\n"
        ."stdClass Object\n"
        ."(\n"
        .")\n"
        ."---\n"
        ."PR cast:\n"
        ."stdClass Object\n"
        ."(\n"
        ."    [a] => 1\n"
        .")\n"
        ."---\n"
        ."VE user:\n"
        ."\\VeUser34506::__set_state(array(\n"
        ."   'n' => 2,\n"
        ."))\n"
        ."---\n";

    public function testAotVarExportPrintRObjectsMatchZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34506_var_export_print_r_object_aot.php';
        $bin = sys_get_temp_dir().'/phpc_issue_34506_obj_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $matched = 0;
            for ($i = 0; $i < 5; ++$i) {
                $outFile = $bin.'.out';
                $runRc = 0;
                system(escapeshellarg($bin).' > '.escapeshellarg($outFile).' 2>&1', $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.(string) @file_get_contents($outFile));
                $this->assertSame(self::EXPECTED, (string) file_get_contents($outFile), 'run '.($i + 1));
                @unlink($outFile);
                ++$matched;
            }
            $this->assertSame(5, $matched);
        } finally {
            @unlink($bin);
            @unlink($bin.'.out');
        }
    }
}
