<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: array_fill / array_fill_keys / range excess argc → ArgumentCountError (#30719).
 *
 * php-src: ext/standard/array.c
 *
 * @group llvm
 * @group aot
 */
final class Issue30719ArrayFillRangeExcessArgcAotTest extends TestCase
{
    public function testAotExcessArgcCatchableUnderTry(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30719_try_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30719_try_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
try {
    array_fill(0, 1, 'a', 4);
    echo "fill_hi NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'fill_hi ', $e->getMessage(), "\n";
}
try {
    array_fill(0, 1);
    echo "fill_lo NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'fill_lo ', $e->getMessage(), "\n";
}
try {
    array_fill_keys([1], 'a', 3);
    echo "keys_hi NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'keys_hi ', $e->getMessage(), "\n";
}
try {
    array_fill_keys([1]);
    echo "keys_lo NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'keys_lo ', $e->getMessage(), "\n";
}
try {
    range(1, 3, 1, 4);
    echo "range_hi NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'range_hi ', $e->getMessage(), "\n";
}
try {
    range(1);
    echo "range_lo NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'range_lo ', $e->getMessage(), "\n";
}
$f = array_fill(0, 1, 'a');
echo ($f[0] === 'a') ? "ok_fill\n" : "bad_fill\n";
$k = array_fill_keys([1], 'a');
echo ($k[1] === 'a') ? "ok_keys\n" : "bad_keys\n";
$r = range(1, 3);
echo (is_array($r) && count($r) === 3 && $r[0] == 1 && $r[2] == 3) ? "ok_range\n" : "bad_range\n";
PHP);
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
                $this->assertSame(0, $runRc, 'run '.$i.': '.implode("\n", $runOut));
                $this->assertSame(
                    "fill_hi array_fill() expects exactly 3 arguments, 4 given\n"
                    ."fill_lo array_fill() expects exactly 3 arguments, 2 given\n"
                    ."keys_hi array_fill_keys() expects exactly 2 arguments, 3 given\n"
                    ."keys_lo array_fill_keys() expects exactly 2 arguments, 1 given\n"
                    ."range_hi range() expects at most 3 arguments, 4 given\n"
                    ."range_lo range() expects at least 2 arguments, 1 given\n"
                    ."ok_fill\n"
                    ."ok_keys\n"
                    ."ok_range\n",
                    implode("\n", $runOut)."\n",
                    'run '.$i
                );
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
