<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_strwidth()/mb_strimwidth() runtime encoding via MbStrwidthJitHelper (#34884 leftover of #34264).
 *
 * @see php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_strwidth), PHP_FUNCTION(mb_strimwidth)
 *
 * @group llvm
 * @group aot
 */
final class MbStrwidthRuntimeEncodingAotTest extends TestCase
{
    public function testAotRuntimeEncodingMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertAotMatchesZend(__DIR__.'/../repro/mb_strwidth_runtime_encoding_aot.php');
    }

    public function testHelperAndLoweringPresent(): void
    {
        $root = dirname(__DIR__, 2);
        $helper = (string) file_get_contents($root.'/ext/mbstring/MbStrwidthJitHelper.php');
        $this->assertStringContainsString('function assertEncodingArgv', $helper);
        $this->assertStringContainsString('Argument #', $helper);
        $this->assertStringNotContainsString('private static function canon', $helper);
        $jit = (string) file_get_contents($root.'/ext/mbstring/JitMbStrwidth.php');
        $this->assertStringContainsString('encodingPtr', $jit);
        $this->assertStringContainsString('linkAndEncodingPtr', $jit);
        $this->assertStringNotContainsString(
            'encoding must be a string literal in this compiler build',
            $jit
        );
        $runtime = (string) file_get_contents($root.'/lib/JIT/Builtin/MbStrwidth.php');
        $this->assertStringContainsString('assertEncodingHelper', $runtime);
        $this->assertStringContainsString('ASSERT_ENCODING_LOGICAL', $runtime);
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/mb_strwidth.c');
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/mb_strimwidth.c');
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
        $bin = sys_get_temp_dir().'/mb_strwidth_enc_'.getmypid().'_'.md5($src);
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
