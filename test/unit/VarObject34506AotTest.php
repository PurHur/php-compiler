<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: var_export()/print_r()/var_dump() on objects must not SIGABRT (#34506).
 *
 * Thin scalar bridges aborted TYPE_OBJECT (#26855 / #24266 / #23540). Fix:
 * VarExportObjectLlvm / PrintRObjectLlvm / VarDumpObjectLlvm with call-site
 * get_object_vars extraction (peer SerializeObjectPropsLlvm #34493).
 *
 * @see php-src ext/standard/var.c php_var_export_ex / zend_print_zval_r / php_var_dump
 *
 * @group llvm
 * @group aot
 */
final class VarObject34506AotTest extends TestCase
{
    private const EXPECTED = <<<'EOT'
(object) array(
)
(object) array(
   'a' => 1,
)
(object) array(
   'a' => 1,
   'b' => 
  array (
    0 => 2,
  ),
)
stdClass Object
(
    [a] => 1
)
object(stdClass)#1 (1) {
  ["a"]=>
  int(1)
}
object(stdClass)#1 (0) {
}
EOT;

    public function testVmVarObjectFnsMatchZendShape(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_34506_var_object_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34506_var_object_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertStringContainsString('(object) array(', $out);
        $this->assertStringContainsString("'a' => 1", $out);
        $this->assertStringContainsString('stdClass Object', $out);
        $this->assertStringContainsString('object(stdClass)#', $out);
    }

    public function testAotVarObjectFnsMatchZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34506_var_object_aot.php';
        $bin = sys_get_temp_dir().'/phpc_issue_34506_vo_'.getmypid().'.bin';
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
                $this->assertSame(self::EXPECTED."\n", $out, 'run '.($i + 1));
                ++$matched;
            }
            $this->assertSame(5, $matched);
        } finally {
            @unlink($bin);
        }
    }
}
