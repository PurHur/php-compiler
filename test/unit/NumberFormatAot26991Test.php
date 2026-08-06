<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT execute guard for number_format() multi-call + negatives (#26991).
 *
 * NestedJIT of SprintfJitHelper used to segfault on a second default-separator
 * call after thousands grouping, and on '-'.$result for negatives.
 *
 * php-src: ext/standard/formatted_print.c / math.c — PHP_FUNCTION(number_format)
 *
 * @group llvm
 * @group aot
 */
final class NumberFormatAot26991Test extends TestCase
{
    public function testAotNumberFormatMultiCallAndNegativeMatchZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_number_format_26991_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
echo number_format(1234.567, 2, ",", " "), "\n";
echo number_format(1234.567, 2), "\n";
echo number_format(1234.5, 0, ".", ","), "\n";
echo number_format(-1234.567, 2, ",", " "), "\n";
echo number_format(1234.567, 2), "\n";
PHP);
        $bin = sys_get_temp_dir().'/phpc_number_format_26991_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        $expected = "1 234,57\n1,234.57\n1,235\n-1 234,57\n1,234.57\n";
        try {
            for ($i = 0; $i < 10; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame($expected, implode("\n", $runOut)."\n", 'run '.($i + 1));
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
