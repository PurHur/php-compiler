<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT DateTime::diff after two locals — unnamed New_ must still publish stamps (#33906, re-#27309).
 *
 * @group llvm
 * @group aot
 */
final class Issue33906DateTimeDiffTwoLocalsAotTest extends TestCase
{
    public function testConstructSyncPublishesUnnamedNewOntoUnstampedLocal(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertStringContainsString('re-#27309', $source);
        $this->assertStringContainsString('first DateTime-shaped', $source);
        $this->assertStringContainsString('applyDateTimeLocalInstantsToCallArgs', $source);
    }

    public function testResolveCompileTimeInstantPrefersDedicatedTimestampField(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/JitDateMutation.php'
        );
        $this->assertStringContainsString('compileTimeDateTimeTimestamp', $source);
        $this->assertStringContainsString('lowerDiffRuntime', $source);
    }

    public function testAotTwoLocalDiffMatchesZend(): void
    {
        if (!\PHPCompiler\LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33906_datetime_diff_two_locals_aot.php';
        $bin = sys_get_temp_dir().'/phpc_diff_33906_'.getmypid().'.bin';
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
                $this->assertSame("9\n", implode("\n", $runOut)."\n", 'run '.($i + 1));
            }
        } finally {
            @unlink($bin);
        }
    }
}
