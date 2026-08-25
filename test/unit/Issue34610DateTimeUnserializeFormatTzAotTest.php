<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT unserialize(DateTime)::format(T/e/O/P) (#34610).
 *
 * @group llvm
 * @group aot
 */
final class Issue34610DateTimeUnserializeFormatTzAotTest extends TestCase
{
    public function testFormatHelperEarlyReturnsTimezoneTokens(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/DateTimeFormatJitHelper.php'
        );
        $this->assertStringContainsString('#34610', $source);
        $this->assertStringContainsString('formatObjectTimezoneToken', $source);
        $this->assertStringContainsString('Y-m-d H:i:s T', $source);

        $vm = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/VM/DateTimeFormatJitHelper.php'
        );
        $this->assertStringContainsString('#34610', $vm);
        $this->assertStringContainsString('concatStringValues', $vm);
    }

    public function testAotUnserializeFormatTzMatchesZend(): void
    {
        if (!\PHPCompiler\LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34610_datetime_unserialize_format_tz_aot.php';
        $bin = sys_get_temp_dir().'/phpc_dt_unser_tz_34610_'.getmypid().'.bin';
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
