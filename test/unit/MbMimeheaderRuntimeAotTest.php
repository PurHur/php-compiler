<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_encode/decode_mimeheader() runtime via MbMimeheaderJitHelper (#34299 leftover of #6038).
 *
 * @see php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_encode_mimeheader)
 * @see php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_decode_mimeheader)
 *
 * @group llvm
 * @group aot
 */
final class MbMimeheaderRuntimeAotTest extends TestCase
{
    public function testAotRuntimeMatchMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertAotMatchesZend(__DIR__.'/../repro/mb_mimeheader_runtime_aot.php');
    }

    public function testHelperAndLoweringPresent(): void
    {
        $root = dirname(__DIR__, 2);
        $helper = (string) file_get_contents($root.'/ext/mbstring/MbMimeheaderJitHelper.php');
        $this->assertStringContainsString('function encodeArgv', $helper);
        $this->assertStringContainsString('function decodeArgv', $helper);
        $this->assertStringContainsString('VmMbstring::encodeMimeheader', $helper);
        $this->assertStringContainsString('VmMbstring::decodeMimeheader', $helper);
        $runtime = (string) file_get_contents($root.'/lib/JIT/Builtin/MbMimeheaderRuntime.php');
        $this->assertStringContainsString('encodeHelper', $runtime);
        $this->assertStringContainsString('decodeHelper', $runtime);
        $enc = (string) file_get_contents($root.'/ext/mbstring/mb_encode_mimeheader.php');
        $this->assertStringContainsString('JitMbMimeheader::invokeEncode', $enc);
        $this->assertStringNotContainsString(
            'mb_encode_mimeheader() is not lowered for JIT/AOT in this compiler build',
            $enc
        );
        $dec = (string) file_get_contents($root.'/ext/mbstring/mb_decode_mimeheader.php');
        $this->assertStringContainsString('JitMbMimeheader::invokeDecode', $dec);
        $this->assertStringNotContainsString(
            'mb_decode_mimeheader() is not lowered for JIT/AOT in this compiler build',
            $dec
        );
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/mb_encode_mimeheader.c');
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/phpc_mb_mimeheader.c');
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
        $bin = sys_get_temp_dir().'/mb_mimeheader_'.getmypid().'_'.md5($src);
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
