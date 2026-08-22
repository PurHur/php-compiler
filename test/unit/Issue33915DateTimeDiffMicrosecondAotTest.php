<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT DateTime::diff must keep construct microseconds (#33915, leftover #33912).
 *
 * php-src: ext/date/php_date.c — zim_DateTime_diff / timelib_diff
 *
 * @group llvm
 * @group aot
 */
final class Issue33915DateTimeDiffMicrosecondAotTest extends TestCase
{
    public function testConstructStampsMicrosecond(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/JitDateTimeConstruct.php'
        );
        $this->assertStringContainsString('compileTimeDateTimeMicrosecond', $source);
    }

    public function testResolveCompileTimeInstantReadsMicro(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/JitDateMutation.php'
        );
        $this->assertStringContainsString('compileTimeDateTimeMicrosecond ?? 0', $source);
        $this->assertStringContainsString('#33915', $source);
    }

    public function testAotFractionalDiffMatchesZend(): void
    {
        if (!\PHPCompiler\LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33915_dateinterval_diff_f_slot.php';
        $bin = sys_get_temp_dir().'/phpc_di_33915_'.getmypid().'.bin';
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $expect = "0.75\n0\n750000\n0\ndone\n";
        try {
            for ($i = 0; $i < 5; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame($expect, implode("\n", $runOut)."\n", 'run '.($i + 1));
            }
        } finally {
            @unlink($bin);
        }
    }
}
