<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT date('r')/date('c') after date_default_timezone_set (#33964).
 *
 * php-src: ext/date/php_date.c — php_format_date tokens r/c
 *
 * @group llvm
 * @group aot
 */
final class Issue33964DateRcAfterTimezoneSetAotTest extends TestCase
{
    public function testJitDateRoutesRcThroughCivilTokenHelper(): void
    {
        $jit = (string) file_get_contents(dirname(__DIR__, 2).'/ext/standard/JitDate.php');
        $this->assertStringContainsString('#33964', $jit);
        $this->assertStringContainsString('emitDateCWithRuntimeOffset', $jit);
        $this->assertStringContainsString('emitDateRWithRuntimeOffset', $jit);
        $civil = (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/DefaultTimezoneCivilJitHelper.php'
        );
        $this->assertStringContainsString('formatTimezoneToken', $civil);
        $this->assertStringContainsString('formatTokenO', $civil);
    }

    public function testAotDateRcAfterSetMatchesZend(): void
    {
        if (!\PHPCompiler\LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33964_date_rc_after_timezone_set_aot.php';
        $bin = sys_get_temp_dir().'/phpc_date_rc_33964_'.getmypid().'.bin';
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
