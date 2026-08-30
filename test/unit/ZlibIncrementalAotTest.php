<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: incremental zlib leftover of #4656 (#35885).
 *
 * @see php-src ext/zlib/zlib.c PHP_FUNCTION(deflate_init)
 *
 * @group llvm
 * @group aot
 */
final class ZlibIncrementalAotTest extends TestCase
{
    public function testAotRoundTripMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertAotMatchesZend(__DIR__.'/../repro/aot_zlib_incremental.php');
    }

    public function testLoweringPresent(): void
    {
        $root = dirname(__DIR__, 2);
        $src = (string) file_get_contents($root.'/ext/standard/ZlibIncrementalFunction.php');
        $this->assertStringContainsString('JitZlibIncremental::dispatch', $src);
        $this->assertStringNotContainsString(
            "throw new \\LogicException(\$this->getName().'() is not lowered for JIT/AOT",
            $src
        );
        $jit = (string) file_get_contents($root.'/ext/standard/JitZlibIncremental.php');
        $this->assertStringContainsString('JitZlib::compress', $jit);
        $this->assertStringContainsString('JitZlib::uncompress', $jit);
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
        $bin = sys_get_temp_dir().'/zinc_'.getmypid().'_'.md5($src);
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
