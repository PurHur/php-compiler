<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * #27249 — AOT round(M_PI, 5) must match Zend (not truncate to 3).
 *
 * @group llvm
 * @group aot
 */
final class MPiFloatConstAot27249Test extends TestCase
{
    public function testAotRoundMPiMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_m_pi_float_constants_27249.php';
        $bin = sys_get_temp_dir().'/phpc_aot_m_pi_27249_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        putenv('PHP_COMPILER_HELPER_RUNTIME_O=0');
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);

        try {
            $expected = [];
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $expected, $zendRc);
            $this->assertSame(0, $zendRc, implode("\n", $expected));
            $want = implode("\n", $expected)."\n";

            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $got = implode("\n", $runOut)."\n";
            $this->assertSame($want, $got);
            $this->assertStringContainsString('3.14159|3.14159', $got);
        } finally {
            putenv('PHP_COMPILER_HELPER_RUNTIME_O');
            @unlink($bin);
        }
    }
}
