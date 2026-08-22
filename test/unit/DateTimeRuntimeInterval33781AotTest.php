<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT DateTime{,Immutable}::add/sub with a runtime DateInterval (#33781).
 *
 * php-src: ext/date/php_date.c — date_add / DateTime::add
 *
 * @group llvm
 * @group aot
 */
final class DateTimeRuntimeInterval33781AotTest extends TestCase
{
    private function compile(string $srcRelative): string
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/'.$srcRelative;
        $bin = sys_get_temp_dir().'/phpc_dt_ri_33781_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);

        return $bin;
    }

    public function testAotRuntimeIntervalAddSubMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = 'test/repro/issue_33781_datetime_runtime_interval_aot.php';
        $zend = [];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/'.$src).' 2>&1', $zend, $zec);
        $this->assertSame(0, $zec, implode("\n", $zend));
        $want = implode("\n", $zend)."\n";

        $bin = $this->compile($src);
        try {
            $aot = [];
            exec(escapeshellarg($bin).' 2>&1', $aot, $aec);
            $this->assertSame(0, $aec, implode("\n", $aot));
            $this->assertSame($want, implode("\n", $aot)."\n");
        } finally {
            @unlink($bin);
        }
    }

    public function testAddSubRecoversIntervalAndInstantStamps(): void
    {
        $root = dirname(__DIR__, 2);
        $src = (string) file_get_contents($root.'/ext/standard/JitDateMutation.php');
        $this->assertStringContainsString('#33781', $src);
        $this->assertStringContainsString('recoverCompileTimeInstantFromBindings', $src);
        $this->assertStringContainsString('refreshCompileTimeInstantBindings', $src);
        $jit = (string) file_get_contents($root.'/lib/JIT.php');
        $this->assertStringContainsString('applyDateMetaToDateTimeAddSubArgs', $jit);
    }
}
