<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * #32285 follow-up: moduloWithNegOneShortCircuit must not nest PHI/srem so LLVM
 * verify fails when JitLongArg branches before srem (e05_sprintf, j08_preg, …).
 *
 * @group llvm
 * @group aot
 */
final class Issue32285ModNegOnePhiDominanceAotTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string}> */
    public static function compileSmokeCases(): iterable
    {
        yield 'e05_sprintf' => [
            'test/differential/cases/e05_sprintf.php',
            "3-4\na1/b2\n",
        ];
        yield 'j08_preg' => [
            'test/differential/cases/j08_preg.php',
            "int(1)\n12\na_b\n",
        ];
        yield 'j10_array_filter_callback' => [
            'test/differential/cases/j10_array_filter_callback.php',
            "2,4\n",
        ];
    }

    /**
     * @dataProvider compileSmokeCases
     */
    public function testAotCompileAndRunMatchesZend(string $relativeSrc, string $expected): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/'.$relativeSrc;
        $bin = sys_get_temp_dir().'/phpc_32285_phi_'.md5($relativeSrc).'_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame($expected, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
