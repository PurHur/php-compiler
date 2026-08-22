<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: DateTime{,Immutable}::add/sub with runtime DateInterval variable (#33781).
 *
 * @group llvm
 * @group aot
 */
final class Issue33781DateTimeRuntimeIntervalAotTest extends TestCase
{
    public function testAotRuntimeDateIntervalAddSubMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33781_datetime_runtime_interval_aot.php';
        $bin = sys_get_temp_dir().'/phpc_issue_33781_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        $expected = "2020-02-15\n2020-01-16\n";
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame($expected, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
