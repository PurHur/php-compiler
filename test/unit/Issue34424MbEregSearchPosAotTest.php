<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_ereg_search_pos/regs/getpos/getregs/setpos (#34424).
 *
 * @group llvm
 * @group aot
 */
final class Issue34424MbEregSearchPosAotTest extends TestCase
{
    public function testAotMbEregSearchPosRegsGetposSetpos(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/mb_ereg_search_family_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34424_'.getmypid().'.bin';
        $expected = "6,5\n11\n123\n123\n6\n4\n";

        $zendOut = [];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $this->assertSame($expected, implode("\n", $zendOut)."\n");

        try {
            foreach ([0, 1] as $helperO) {
                $compileOut = [];
                $compile = 'PHP_COMPILER_HELPER_RUNTIME_O='.$helperO.' '
                    .escapeshellarg(PHP_BINARY).' '
                    .escapeshellarg($root.'/bin/compile.php')
                    .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
                exec($compile, $compileOut, $compileRc);
                $this->assertSame(
                    0,
                    $compileRc,
                    'HELPER_RUNTIME_O='.$helperO."\n".implode("\n", $compileOut)
                );
                $this->assertFileExists($bin);
                for ($i = 0; $i < 3; ++$i) {
                    $runOut = [];
                    exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                    $this->assertSame(0, $runRc, 'run '.$i.': '.implode("\n", $runOut));
                    $this->assertSame($expected, implode("\n", $runOut)."\n", 'HELPER_O='.$helperO.' run '.$i);
                }
            }
        } finally {
            @unlink($bin);
        }
    }
}
