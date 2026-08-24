<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT DateTime::format / date() runtime format civil dispatch (#34482).
 *
 * @group llvm
 * @group aot
 */
final class Issue34482DateTimeFormatRuntimeAotTest extends TestCase
{
    public function testCivilRuntimeDispatchPresent(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/JitDate.php'
        );
        $this->assertStringContainsString('#34482', $source);
        $this->assertStringContainsString('emitRuntimeCivilFormatDispatch', $source);
        $this->assertStringContainsString('civilLiteralFormatKeys', $source);
    }

    public function testAotRuntimeFormatMatchesVm(): void
    {
        if (!\PHPCompiler\LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34482_datetime_format_runtime_aot.php';
        $bin = sys_get_temp_dir().'/phpc_fmt_rt_34482_'.getmypid().'.bin';
        $env = 'PHP_COMPILER_PROFILE=8.4 PHP_COMPILER_HELPER_RUNTIME_O=0 ';
        $compile = $env.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));

        $vmOut = [];
        exec(
            'PHP_COMPILER_PROFILE=8.4 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '.escapeshellarg($src).' 2>&1',
            $vmOut,
            $vmRc
        );
        $this->assertSame(0, $vmRc, implode("\n", $vmOut));
        $expected = implode("\n", $vmOut)."\n";

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
