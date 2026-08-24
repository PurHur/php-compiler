<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: var_export()/print_r() on arrays must not SIGABRT (#34497).
 *
 * Thin scalar bridges aborted non-scalars (#26855 / #24266); fix: VarExportArrayLlvm
 * + PrintRArrayLlvm (peer SerializeArrayLlvm #34483).
 *
 * @see php-src ext/standard/var.c php_var_export_ex / zend_print_zval_r
 *
 * @group llvm
 * @group aot
 */
final class VarExportPrintRArray34497AotTest extends TestCase
{
    private const EXPECTED = <<<'EOT'
array (
)
array (
  0 => 1,
  1 => 2,
)
array (
  'a' => 1,
  'b' => 2,
)
array (
  0 => 1,
  1 => 
  array (
    0 => 2,
    'x' => 3,
  ),
)
Array
(
)
Array
(
    [0] => 1
)
Array
(
    [k] => Array
        (
            [0] => 1
        )

)

EOT;

    public function testVmVarExportPrintRArraysMatchZendShape(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_34497_varexport_print_r_array_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34497_varexport_print_r_array_aot.php'));
        $out = (string) ob_get_clean();
        // VM var_export nested arrays omit Zend's newline after "=> " — assert no crash
        // and top-level empty/packed/assoc shapes; full Zend match is the AOT gate.
        $this->assertStringContainsString("array (\n)", $out);
        $this->assertStringContainsString('0 => 1', $out);
        $this->assertStringContainsString("'a' => 1", $out);
        $this->assertStringContainsString("Array\n(\n)", $out);
        $this->assertStringContainsString('[0] => 1', $out);
    }

    public function testAotVarExportPrintRArraysMatchZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34497_varexport_print_r_array_aot.php';
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
                // shell_exec (not exec): exec() rtrims each line and drops the space after "=> "
                // that Zend puts before a nested-array newline (php_array_element_export).
                $out = shell_exec(escapeshellarg($bin).' 2>&1');
                $this->assertIsString($out, 'run '.($i + 1).': empty output');
                $this->assertSame(self::EXPECTED, $out, 'run '.($i + 1));
                ++$matched;
            }
            $this->assertSame(5, $matched);
        } finally {
            @unlink($bin);
        }
    }
}
