<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: named string literals + echo $a.$b must not SIGSEGV (#34457).
 *
 * @group llvm
 * @group aot
 */
final class NamedStringEchoConcat34457AotTest extends TestCase
{
    public function testNamedStringEchoConcatMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($root)) {
            $this->markTestSkipped(LlvmToolchain::readyFailureReason() ?? 'LLVM 9 required');
        }
        $src = $root.'/test/repro/issue_34457_named_string_echo_concat.php';
        $this->assertFileExists($src);
        $outBin = sys_get_temp_dir().'/phpc_34457_'.getmypid().'_'.mt_rand().'.bin';
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php').' -o '
            .escapeshellarg($outBin).' '
            .escapeshellarg($src).' 2>&1';
        exec($cmd, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($outBin);
        try {
            exec(escapeshellarg($outBin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame('xy', implode("\n", $runOut));
        } finally {
            @unlink($outBin);
        }
    }
}
