<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_http_output() via MbHttpOutputJitHelper (#35231 leftover of #13100/#20014).
 *
 * @see php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_http_output)
 *
 * @group llvm
 * @group aot
 */
final class MbHttpOutputRuntimeEncodingAotTest extends TestCase
{
    public function testAotRuntimeMatchMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertAotMatchesZend(__DIR__.'/../repro/aot_mb_http_output_runtime_encoding.php');
    }

    public function testHelperAndLoweringPresent(): void
    {
        $root = dirname(__DIR__, 2);
        $helper = (string) file_get_contents($root.'/ext/mbstring/MbHttpOutputJitHelper.php');
        $this->assertStringContainsString('function canonicalizeArgv', $helper);
        $this->assertStringContainsString('CODE_PASS', $helper);
        $runtime = (string) file_get_contents($root.'/lib/JIT/Builtin/MbHttpOutputRuntime.php');
        $this->assertStringContainsString('canonicalizeHelper', $runtime);
        $this->assertStringContainsString('G_ENCODING_CODE', $runtime);
        $jit = (string) file_get_contents($root.'/ext/mbstring/JitMbHttpOutput.php');
        $this->assertStringContainsString('MbHttpOutputRuntime', $jit);
        $src = (string) file_get_contents($root.'/ext/mbstring/mb_http_output.php');
        $this->assertStringContainsString('JitMbHttpOutput::invoke', $src);
        $this->assertStringNotContainsString(
            'mb_http_output() encoding must be a compile-time string',
            $src
        );
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/mb_http_output.c');
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
        $bin = sys_get_temp_dir().'/mb_http_out_'.getmypid().'_'.md5($src);
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
