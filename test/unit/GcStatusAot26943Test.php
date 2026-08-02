<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT execute guard for gc_status() (#26943).
 *
 * php-src: Zend/zend_builtin_functions.c — ZEND_FUNCTION(gc_status)
 *
 * @group llvm
 * @group aot
 */
final class GcStatusAot26943Test extends TestCase
{
    public function testAotGcStatusRunsKeyExecute(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_gc_status_26943_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
$s = gc_status();
echo isset($s['runs']) ? 'ok' : 'bad', "\n";
PHP);
        $bin = sys_get_temp_dir().'/phpc_gc_status_26943_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame("ok\n", implode("\n", $runOut)."\n");
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
