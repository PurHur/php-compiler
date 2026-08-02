<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT: var_export(bool) must not lag echo "\\n" via fully-buffered printf (#26929).
 *
 * php-src: ext/standard/var.c — PHP_FUNCTION(var_export)
 *
 * @group llvm
 * @group aot
 */
final class VarExportBoolAotStdoutOrderTest extends TestCase
{
    public function testAotVarExportBoolThenEchoNewlineOrdered(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = '/tmp/phpc_varexport_bool_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
var_export(true);
echo "\n";
var_export(false);
echo "\n";
PHP);
        $bin = '/tmp/phpc_varexport_bool_'.getmypid().'.bin';
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $runText = implode("\n", $runOut)."\n";
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.$runText);
                $this->assertSame("true\nfalse\n", $runText, 'run '.($i + 1));
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
