<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: var_export()/print_r() on arrays must not SIGABRT (#34497).
 *
 * Thin scalar bridges aborted on TYPE_HASHTABLE; fix: VarExportArrayLlvm /
 * PrintRArrayLlvm (peer SerializeArrayLlvm #34483).
 *
 * @see php-src ext/standard/var.c php_var_export_ex / zend_print_zval_r
 *
 * @group llvm
 * @group aot
 */
final class VarExportPrintRArray34497AotTest extends TestCase
{
    /** Zend 8.2 nested var_export breaks after {@code => } (php_array_element_export). */
    private const EXPECTED_AOT = "VE empty:\n"
        ."array (\n)\n"
        ."---\n"
        ."VE packed:\n"
        ."array (\n"
        ."  0 => 1,\n"
        ."  1 => 2,\n"
        .")\n"
        ."---\n"
        ."VE assoc:\n"
        ."array (\n"
        ."  'a' => 1,\n"
        ."  'b' => 'x',\n"
        .")\n"
        ."---\n"
        ."VE nested:\n"
        ."array (\n"
        ."  0 => \n"
        ."  array (\n"
        ."    0 => 1,\n"
        ."    1 => 2,\n"
        ."  ),\n"
        .")\n"
        ."---\n"
        ."PR empty:\n"
        ."Array\n"
        ."(\n"
        .")\n"
        ."---\n"
        ."PR packed:\n"
        ."Array\n"
        ."(\n"
        ."    [0] => 1\n"
        .")\n"
        ."---\n"
        ."PR nested:\n"
        ."Array\n"
        ."(\n"
        ."    [0] => Array\n"
        ."        (\n"
        ."            [0] => 1\n"
        ."            [1] => 2\n"
        ."        )\n"
        ."\n"
        .")\n"
        ."---\n";

    /** VM SSOT still same-line nested arrays; AOT matches Zend (#34497). */
    private const EXPECTED_VM = "VE empty:\n"
        ."array (\n)\n"
        ."---\n"
        ."VE packed:\n"
        ."array (\n"
        ."  0 => 1,\n"
        ."  1 => 2,\n"
        .")\n"
        ."---\n"
        ."VE assoc:\n"
        ."array (\n"
        ."  'a' => 1,\n"
        ."  'b' => 'x',\n"
        .")\n"
        ."---\n"
        ."VE nested:\n"
        ."array (\n"
        ."  0 => array (\n"
        ."    0 => 1,\n"
        ."    1 => 2,\n"
        ."  ),\n"
        .")\n"
        ."---\n"
        ."PR empty:\n"
        ."Array\n"
        ."(\n"
        .")\n"
        ."---\n"
        ."PR packed:\n"
        ."Array\n"
        ."(\n"
        ."    [0] => 1\n"
        .")\n"
        ."---\n"
        ."PR nested:\n"
        ."Array\n"
        ."(\n"
        ."    [0] => Array\n"
        ."        (\n"
        ."            [0] => 1\n"
        ."            [1] => 2\n"
        ."        )\n"
        ."\n"
        .")\n"
        ."---\n";

    public function testVmVarExportPrintRArraysMatchCurrentSsot(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_34497_var_export_print_r_array_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34497_var_export_print_r_array_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED_VM, $out);
    }

    public function testAotVarExportPrintRArraysMatchZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34497_var_export_print_r_array_aot.php';
        $bin = sys_get_temp_dir().'/phpc_issue_34497_ve_'.getmypid().'.bin';
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
                // exec() strips trailing whitespace per line — Zend nested "=> " needs a file capture.
                system(escapeshellarg($bin).' > '.escapeshellarg($outFile).' 2>&1', $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.(string) @file_get_contents($outFile));
                $this->assertSame(self::EXPECTED_AOT, (string) file_get_contents($outFile), 'run '.($i + 1));
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
