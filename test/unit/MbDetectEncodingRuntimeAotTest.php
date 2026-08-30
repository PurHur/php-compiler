<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_detect_encoding() NestedJIT runtime (#34358 / #35846 leftover of #3075).
 *
 * @see php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_detect_encoding)
 *
 * @group llvm
 * @group aot
 */
final class MbDetectEncodingRuntimeAotTest extends TestCase
{
    public function testAotRuntimeMatchMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertAotMatchesZend(__DIR__.'/../repro/aot_mb_detect_encoding_runtime.php');
    }

    public function testHelperAndLoweringPresent(): void
    {
        $root = dirname(__DIR__, 2);
        $helper = (string) file_get_contents($root.'/ext/mbstring/MbDetectEncodingJitHelper.php');
        $this->assertStringContainsString('function detectArgv', $helper);
        $this->assertStringNotContainsString('VmMbstring::', $helper);
        $runtime = (string) file_get_contents($root.'/lib/JIT/Builtin/MbDetectEncodingRuntime.php');
        $this->assertStringContainsString('detectHelper', $runtime);
        $this->assertStringContainsString('MbDetectEncodingJitHelper::detectArgv', $runtime);
        $this->assertStringContainsString('lookupCompiled', $runtime);
        $helper = (string) file_get_contents($root.'/ext/mbstring/MbDetectEncodingJitHelper.php');
        $this->assertStringContainsString('string $strictFlag', $helper);
        $this->assertStringNotContainsString('int $strict', $helper);
        $this->assertStringContainsString('strpos', $helper);
        $jit = (string) file_get_contents($root.'/ext/mbstring/JitMbDetectEncoding.php');
        $this->assertStringContainsString('Link NestedJIT helpers before lowering args', $jit);
        $this->assertStringContainsString('strictFlagString', $jit);
        $src = (string) file_get_contents($root.'/ext/mbstring/mb_detect_encoding.php');
        $this->assertStringContainsString('JitMbDetectEncoding::invoke', $src);
        $this->assertStringNotContainsString(
            'mb_detect_encoding() JIT requires a compile-time string literal (1-arg)',
            $src
        );
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/mb_detect_encoding.c');
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/phpc_mb_detect_encoding.c');
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
        $bin = sys_get_temp_dir().'/mb_detect_'.getmypid().'_'.md5($src);
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
