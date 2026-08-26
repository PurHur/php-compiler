<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_internal_encoding() via MbInternalEncodingJitHelper (#35221 leftover of #13100).
 *
 * @see php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_internal_encoding)
 *
 * @group llvm
 * @group aot
 */
final class MbInternalEncodingRuntimeAotTest extends TestCase
{
    public function testAotRuntimeMatchMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertAotMatchesZend(__DIR__.'/../repro/aot_mb_internal_encoding_runtime.php');
    }

    public function testHelperAndLoweringPresent(): void
    {
        $root = dirname(__DIR__, 2);
        $helper = (string) file_get_contents($root.'/ext/mbstring/MbInternalEncodingJitHelper.php');
        $this->assertStringContainsString('function canonicalizeArgv', $helper);
        $this->assertStringContainsString('CODE_ISO88591', $helper);
        $runtime = (string) file_get_contents($root.'/lib/JIT/Builtin/MbInternalEncodingRuntime.php');
        $this->assertStringContainsString('canonicalizeHelper', $runtime);
        $this->assertStringContainsString('G_ENCODING_CODE', $runtime);
        $this->assertStringContainsString('MbInternalEncodingJitHelper::canonicalizeArgv', $runtime);
        $jit = (string) file_get_contents($root.'/ext/mbstring/JitMbInternalEncoding.php');
        $this->assertStringContainsString('MbInternalEncodingRuntime', $jit);
        $src = (string) file_get_contents($root.'/ext/mbstring/mb_internal_encoding.php');
        $this->assertStringContainsString('JitMbInternalEncoding::invoke', $src);
        $this->assertStringNotContainsString(
            'mb_internal_encoding() encoding must be a compile-time string',
            $src
        );
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/mb_internal_encoding.c');
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/phpc_mb_internal_encoding.c');
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
        $bin = sys_get_temp_dir().'/mb_internal_enc_'.getmypid().'_'.md5($src);
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
