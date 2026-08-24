<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: var_export()/print_r()/var_dump() on objects must not SIGABRT (#34506).
 *
 * Thin scalar bridges aborted TYPE_OBJECT (#26855 / #23540); fix: VarExportObjectLlvm
 * + PrintRObjectLlvm + VarDumpObjectLlvm (peer array bridges #34497 / #34498).
 *
 * @see php-src ext/standard/var.c php_var_export_ex / zend_print_zval_r / php_var_dump
 *
 * @group llvm
 * @group aot
 */
final class VarExportPrintRVarDumpObject34506AotTest extends TestCase
{
    private const EXPECTED = <<<'EOT'
(object) array(
)
(object) array(
   'a' => 1,
)
(object) array(
   'a' => 1,
   'nest' => 
  array (
    0 => 2,
  ),
)
stdClass Object
(
)
stdClass Object
(
    [a] => 1
)
stdClass Object
(
    [a] => 1
    [nest] => Array
        (
            [0] => 2
        )

)
object(stdClass)#1 (0) {
}
object(stdClass)#2 (1) {
  ["a"]=>
  int(1)
}
object(stdClass)#3 (2) {
  ["a"]=>
  int(1)
  ["nest"]=>
  array(1) {
    [0]=>
    int(2)
  }
}

EOT;

    public function testVmVarExportPrintRVarDumpObjectsNoCrash(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_34506_varexport_print_r_vardump_object_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34506_varexport_print_r_vardump_object_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertStringContainsString('(object) array(', $out);
        $this->assertStringContainsString('stdClass Object', $out);
        $this->assertStringContainsString('object(stdClass)#', $out);
        $this->assertStringContainsString('["a"]=>', $out);
    }

    public function testAotVarExportPrintRVarDumpObjectsMatchZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34506_varexport_print_r_vardump_object_aot.php';
        $bin = sys_get_temp_dir().'/phpc_issue_34506_ve_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $matched = 0;
            for ($i = 0; $i < 5; ++$i) {
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
