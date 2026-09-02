<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT execute guard: range() int/char peels must verify + match Zend (#33896).
 *
 * Mid-invoke RangeIntRuntime previously appended loop blocks to the void user fn
 * (BasicBlockHelper prefers loweringLlvmFunction) → Module.php:180 verify failure.
 *
 * php-src: ext/standard/array.c — PHP_FUNCTION(range)
 *
 * @group llvm
 * @group aot
 */
final class RangeIntAot33896Test extends TestCase
{
    /**
     * @dataProvider rangeReproProvider
     */
    public function testAotRangeMatchesZend(string $relativeSrc): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/'.$relativeSrc;
        $bin = sys_get_temp_dir().'/phpc_range_33896_'.getmypid().'_'.md5($relativeSrc).'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        $expected = [];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $expected, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $expected));
        $want = implode("\n", $expected)."\n";
        try {
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame($want, implode("\n", $runOut)."\n", 'run '.($i + 1));
            }
        } finally {
            @unlink($bin);
        }
    }

    /** @return list<array{0: string}> */
    public static function rangeReproProvider(): array
    {
        return [
            ['test/repro/aot_range_int.php'],
            ['test/repro/aot_range_char.php'],
            ['test/repro/aot_range_float.php'],
            ['test/repro/aot_range_runtime_int.php'],
        ];
    }
}
