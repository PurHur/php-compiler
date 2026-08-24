<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: var_export then print_r must keep distinct string keys (#34514).
 *
 * @see php-src ext/standard/var.c — export/print must not mutate the zval
 *
 * @group llvm
 * @group aot
 */
final class VarExportPrintRKeys34514AotTest extends TestCase
{
    private const EXPECTED = <<<'EOT'
array (
  'a' => 1,
  'b' => 2,
)
Array
(
    [a] => 1
    [b] => 2
)

EOT;

    public function testAotVarExportThenPrintRKeepsKeys(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34514_varexport_print_r_keys.php';
        $bin = sys_get_temp_dir().'/phpc_issue_34514_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            for ($i = 0; $i < 5; ++$i) {
                $out = shell_exec(escapeshellarg($bin).' 2>&1');
                $this->assertIsString($out, 'run '.($i + 1));
                $this->assertSame(self::EXPECTED, $out, 'run '.($i + 1));
            }
        } finally {
            @unlink($bin);
        }
    }
}
