<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT: (array) stdClass / ArrayObject — NestedJIT SPL bypass (#33863 follow-up).
 *
 * @group llvm
 * @group aot
 */
final class CastArrayObject33863AotTest extends TestCase
{
    public function testAotObjectArrayCastMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33863_cast_array_object_aot.php';
        $bin = sys_get_temp_dir().'/phpc_cast_array_obj_33863_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        putenv('PHP_COMPILER_HELPER_RUNTIME_O=0');
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        $expected = [];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $expected, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $expected));
        $want = implode("\n", $expected)."\n";
        try {
            $got = [];
            exec(escapeshellarg($bin).' 2>&1', $got, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $got));
            $this->assertSame($want, implode("\n", $got)."\n");
        } finally {
            if (is_file($bin)) {
                unlink($bin);
            }
        }
    }
}
