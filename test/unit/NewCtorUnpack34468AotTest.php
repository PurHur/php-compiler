<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: new C(...$a) / instance method unpack must keep $this prefix (#34468).
 *
 * @group llvm
 * @group aot
 */
final class NewCtorUnpack34468AotTest extends TestCase
{
    public function testNewAndInstanceUnpackMatchZend(): void
    {
        $root = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($root)) {
            $this->markTestSkipped(LlvmToolchain::readyFailureReason() ?? 'LLVM 9 required');
        }
        $src = $root.'/test/repro/issue_34468_new_ctor_unpack.php';
        $this->assertFileExists($src);
        $outBin = sys_get_temp_dir().'/phpc_34468_'.getmypid().'_'.mt_rand().'.bin';
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
            $this->assertSame("3\n4\n5\n13", implode("\n", $runOut));
        } finally {
            @unlink($outBin);
        }
    }
}
