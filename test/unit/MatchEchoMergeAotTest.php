<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT echo of match() result must not segfault (#24143).
 *
 * @group llvm
 * @group aot
 */
final class MatchEchoMergeAotTest extends TestCase
{
    public function testAotEchoMatchWithDefaultPrintsArm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_24143_aot_match_echo.php';
        $bin = sys_get_temp_dir().'/phpc_match_24143_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            for ($i = 0; $i < 10; ++$i) {
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame("b\n", implode("\n", $runOut)."\n");
                $runOut = [];
            }
        } finally {
            @unlink($bin);
        }
    }
}
