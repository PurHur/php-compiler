<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: non-foldable mb_encode_mimeheader→decode NestedJIT roundtrip (#34310 leftover of #34299/#34307).
 *
 * @see php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_encode_mimeheader)
 *
 * @group llvm
 * @group aot
 */
final class MbMimeheaderRoundtripAotTest extends TestCase
{
    public function testAotRuntimeRoundtripMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertAotMatchesZend(__DIR__.'/../repro/mb_mimeheader_runtime_roundtrip_aot.php');
    }

    public function testHelperIsNestedJitSafePeel(): void
    {
        $root = dirname(__DIR__, 2);
        $helper = (string) file_get_contents($root.'/ext/mbstring/MbMimeheaderJitHelper.php');
        $this->assertStringContainsString('function b64Encode', $helper);
        $this->assertStringContainsString('Base64JitHelper::decodeArgv', $helper);
        $this->assertStringNotContainsString('VmMbstring::', $helper);
        $runtime = (string) file_get_contents($root.'/lib/JIT/Builtin/MbMimeheaderRuntime.php');
        $this->assertStringContainsString('ensureCompiledBundle', $runtime);
        $this->assertStringContainsString('Base64JitHelper.php', $runtime);
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/mb_encode_mimeheader.c');
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
        $bin = sys_get_temp_dir().'/mb_mime_rt_'.getmypid().'_'.md5($src);
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
