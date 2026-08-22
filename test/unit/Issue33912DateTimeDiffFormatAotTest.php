<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT DateInterval::format after DateTime::diff (#33912).
 *
 * @group llvm
 * @group aot
 */
final class Issue33912DateTimeDiffFormatAotTest extends TestCase
{
    public function testFormatRuntimeRestoresInsertBlock(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Builtin/DateIntervalFormatRuntime.php'
        );
        $this->assertStringContainsString('Save/restore insert block', $source);
        $this->assertStringContainsString('#33912', $source);
        $this->assertStringContainsString('restoreCallerInsert', $source);
    }

    public function testDiffMaterializeStoresFAsValueSlot(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/JitDateMutation.php'
        );
        $this->assertStringContainsString('lastDateIntervalDiffState', $source);
        $this->assertStringContainsString('__value__writeDouble', $source);
        $this->assertStringContainsString('#33912', $source);
    }

    public function testAotDiffFormatMatchesZend(): void
    {
        if (!\PHPCompiler\LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33912_datetime_diff_format_aot.php';
        $expected = shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src));
        $this->assertIsString($expected);
        $this->assertNotSame('', $expected);

        $bin = sys_get_temp_dir().'/phpc_diff_fmt_33912_'.getmypid().'.bin';
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
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
