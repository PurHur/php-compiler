<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: var_dump() on arrays must not SIGABRT (#34498).
 *
 * Thin scalar bridge aborted non-scalars (#23540); fix: VarDumpArrayLlvm
 * (peer PrintRArrayLlvm / VarExportArrayLlvm #34497 / SerializeArrayLlvm #34483).
 *
 * @see php-src ext/standard/var.c php_var_dump / php_array_element_dump
 *
 * @group llvm
 * @group aot
 */
final class VarDumpArray34498AotTest extends TestCase
{
    private const EXPECTED = <<<'EOT'
array(0) {
}
array(2) {
  [0]=>
  int(1)
  [1]=>
  int(2)
}
array(2) {
  ["a"]=>
  int(1)
  ["b"]=>
  int(2)
}
array(2) {
  [0]=>
  int(1)
  [1]=>
  array(2) {
    [0]=>
    int(2)
    ["x"]=>
    int(3)
  }
}

EOT;

    public function testVmVarDumpArraysMatchZendShape(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_34498_vardump_array_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34498_vardump_array_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertStringContainsString("array(0) {\n}", $out);
        $this->assertStringContainsString("[0]=>\n  int(1)", $out);
        $this->assertStringContainsString('["a"]=>', $out);
        $this->assertStringContainsString("array(2) {\n    [0]=>\n    int(2)", $out);
    }

    public function testAotVarDumpArraysMatchZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34498_vardump_array_aot.php';
        $bin = sys_get_temp_dir().'/phpc_issue_34498_vd_'.getmypid().'.bin';
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
