<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_strrchr()/mb_strrichr() runtime args via MbSearchJitHelper (peer of #34211 / #20006).
 *
 * @see php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_strrchr), mb_strrichr
 *
 * @group llvm
 * @group aot
 */
final class MbStrrchrRuntimeAotTest extends TestCase
{
    public function testAotRuntimeMatchMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertAotMatchesZend(__DIR__.'/../repro/mb_strrchr_runtime_aot.php');
    }

    public function testHelperAndLoweringPresent(): void
    {
        $root = dirname(__DIR__, 2);
        $helper = (string) file_get_contents($root.'/ext/mbstring/MbSearchJitHelper.php');
        $this->assertStringContainsString('function strrchrArgv', $helper);
        $this->assertStringContainsString('function strrichrArgv', $helper);
        $runtime = (string) file_get_contents($root.'/lib/JIT/Builtin/MbSearchRuntime.php');
        $this->assertStringContainsString('strrchrHelper', $runtime);
        $this->assertStringContainsString('strrichrHelper', $runtime);
        $rchr = (string) file_get_contents($root.'/ext/mbstring/mb_strrchr.php');
        $this->assertStringContainsString('JitMbSearch::invokeStrrchr', $rchr);
        $this->assertStringNotContainsString(
            "throw new \\LogicException('mb_strrchr() is not lowered for JIT/AOT",
            $rchr
        );
        $richr = (string) file_get_contents($root.'/ext/mbstring/mb_strrichr.php');
        $this->assertStringContainsString('JitMbSearch::invokeStrrichr', $richr);
        $this->assertStringNotContainsString(
            "throw new \\LogicException('mb_strrichr() is not lowered for JIT/AOT",
            $richr
        );
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/mb_strrchr.c');
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/mb_strrichr.c');
    }

    private function assertAotMatchesZend(string $src): void
    {
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
    }

    private function runPhp(string $src): string
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/mb_strrchr_'.getmypid().'_'.md5($src);
        $cmd = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $compOut, $compRc);
            $this->assertSame(0, $compRc, implode("\n", $compOut));
            $this->assertFileExists($bin);
            exec(escapeshellarg($bin).' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            chdir($cwd);
            @unlink($bin);
        }
    }
}
