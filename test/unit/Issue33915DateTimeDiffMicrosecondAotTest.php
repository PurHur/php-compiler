<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT DateTime::diff must keep fractional microseconds (#33915).
 *
 * @group llvm
 * @group aot
 */
final class Issue33915DateTimeDiffMicrosecondAotTest extends TestCase
{
    public function testConstructAndResolveStampMicrosecond(): void
    {
        $construct = (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/JitDateTimeConstruct.php'
        );
        $this->assertStringContainsString('compileTimeDateTimeMicrosecond', $construct);

        $mutation = (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/JitDateMutation.php'
        );
        $this->assertStringContainsString('compileTimeDateTimeMicrosecond ?? 0', $mutation);
        $this->assertStringNotContainsString(
            "'microsecond' => 0,\n                'timezone' => \$tz,",
            $mutation
        );

        $variable = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Variable.php'
        );
        $this->assertStringContainsString('compileTimeDateTimeMicrosecond', $variable);
    }

    public function testAotFractionalDiffMatchesZend(): void
    {
        if (!\PHPCompiler\LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33915_dateinterval_diff_f_slot.php';
        $bin = sys_get_temp_dir().'/phpc_diff_us_33915_'.getmypid().'.bin';
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));

        $zendOut = [];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $expected = implode("\n", $zendOut)."\n";

        try {
            for ($i = 0; $i < 5; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame($expected, implode("\n", $runOut)."\n", 'run '.($i + 1));
            }
        } finally {
            @unlink($bin);
        }
    }
}
