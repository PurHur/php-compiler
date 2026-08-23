<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_ord() runtime args via MbChrOrdJitHelper (#34243 leftover of #33547).
 *
 * @see php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_ord)
 *
 * @group llvm
 * @group aot
 */
final class MbOrdRuntimeAotTest extends TestCase
{
    public function testAotRuntimeMatchMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertAotMatchesZend(__DIR__.'/../repro/mb_ord_runtime_aot.php');
    }

    public function testHelperAndLoweringPresent(): void
    {
        $root = dirname(__DIR__, 2);
        $helper = (string) file_get_contents($root.'/ext/mbstring/MbChrOrdJitHelper.php');
        $this->assertStringContainsString('function ordArgv', $helper);
        $runtime = (string) file_get_contents($root.'/lib/JIT/Builtin/MbChrOrdRuntime.php');
        $this->assertStringContainsString('ordHelper', $runtime);
        $src = (string) file_get_contents($root.'/ext/mbstring/mb_ord.php');
        $this->assertStringContainsString('JitMbChrOrd::invokeOrd', $src);
        $this->assertStringNotContainsString(
            "throw new \\LogicException('mb_ord() is not lowered for JIT/AOT",
            $src
        );
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/mb_ord.c');
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
        $bin = sys_get_temp_dir().'/mb_ord_'.getmypid().'_'.md5($src);
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
