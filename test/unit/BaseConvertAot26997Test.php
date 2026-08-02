<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT execute guard for base_convert() after JitIntdiv namespace fix (#26997).
 *
 * php-src: ext/standard/math.c — PHP_FUNCTION(base_convert)
 * Root cause: base_convert_.php imported PHPCompiler\JIT\JitIntdiv (missing);
 * live helper is PHPCompiler\ext\standard\JitIntdiv (same namespace as the builtin).
 *
 * @group llvm
 * @group aot
 */
final class BaseConvertAot26997Test extends TestCase
{
    public function testAotBaseConvertExecute(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_base_convert_26997_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
echo base_convert('a37334', 16, 2), "\n";
PHP);
        $bin = sys_get_temp_dir().'/phpc_base_convert_26997_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            for ($i = 0; $i < 10; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame("101000110111001100110100\n", implode("\n", $runOut)."\n");
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
