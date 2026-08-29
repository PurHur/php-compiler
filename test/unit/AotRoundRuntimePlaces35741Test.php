<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\LlvmToolchain;
use PHPUnit\Framework\TestCase;

/**
 * #35741 — AOT round(runtime float, compile-time precision) must not SIGABRT.
 *
 * @group llvm
 * @group aot
 */
final class AotRoundRuntimePlaces35741Test extends TestCase
{
    public function testAotRoundRuntimePlacesMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_round_runtime_places_35741.php';
        $bin = sys_get_temp_dir().'/phpc_aot_round_places_35741_'.getmypid().'.bin';
        putenv('PHP_COMPILER_HELPER_RUNTIME_O=0');
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));

        try {
            $expected = [];
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $expected, $zendRc);
            $this->assertSame(0, $zendRc);
            $want = implode("\n", $expected)."\n";

            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame($want, implode("\n", $runOut)."\n");
        } finally {
            putenv('PHP_COMPILER_HELPER_RUNTIME_O');
            @unlink($bin);
        }
    }
}
