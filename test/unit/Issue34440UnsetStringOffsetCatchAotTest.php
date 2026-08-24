<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: try { unset($s[$i]) } on a string catches Error like Zend (#34440).
 *
 * @group llvm
 * @group aot
 */
final class Issue34440UnsetStringOffsetCatchAotTest extends TestCase
{
    public function testCaughtUnsetStringOffsetMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_aot_unset_string_offset_catch.php';
        $bin = sys_get_temp_dir().'/phpc_34440_'.getmypid().'.bin';
        $expected = "Error: Cannot unset string offsets\n";

        $zendOut = [];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $this->assertSame($expected, implode("\n", $zendOut)."\n");

        try {
            $compileOut = [];
            $compile = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg($root.'/bin/compile.php')
                .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
            exec($compile, $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, implode("\n", $compileOut));
            $this->assertFileExists($bin);
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.$i.': '.implode("\n", $runOut));
                $this->assertSame($expected, implode("\n", $runOut)."\n", 'run '.$i);
            }
        } finally {
            @unlink($bin);
        }
    }

    public function testUncaughtUnsetStringOffsetFatals(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_aot_unset_string_offset_uncaught.php';
        $bin = sys_get_temp_dir().'/phpc_34440u_'.getmypid().'.bin';

        try {
            $compileOut = [];
            $compile = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg($root.'/bin/compile.php')
                .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
            exec($compile, $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, implode("\n", $compileOut));
            $this->assertFileExists($bin);
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(255, $runRc, implode("\n", $runOut));
            $joined = implode("\n", $runOut);
            $this->assertStringContainsString('Cannot unset string offsets', $joined);
            $this->assertStringNotContainsString('survived', $joined);
        } finally {
            @unlink($bin);
        }
    }
}
