<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: iconv_mime_decode_headers() NestedJIT leftover of #19448 (#34441).
 *
 * @see php-src ext/iconv/iconv.c PHP_FUNCTION(iconv_mime_decode_headers)
 *
 * @group llvm
 * @group aot
 */
final class IconvMimeDecodeHeadersRuntimeAotTest extends TestCase
{
    public function testAotRuntimeMatchMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertAotMatchesZend(__DIR__.'/../repro/aot_iconv_mime_decode_headers.php');
    }

    public function testHelperAndLoweringPresent(): void
    {
        $root = dirname(__DIR__, 2);
        $helper = (string) file_get_contents($root.'/ext/iconv/IconvMimeJitHelper.php');
        $this->assertStringContainsString('function mimeDecodeHeadersArgv', $helper);
        $runtime = (string) file_get_contents($root.'/lib/JIT/Builtin/StringIconvMime.php');
        $this->assertStringContainsString('__compiler_iconv_mime_decode_headers', $runtime);
        $this->assertStringContainsString('mimeDecodeHeadersArgv', $runtime);
        $src = (string) file_get_contents($root.'/ext/iconv/iconv_mime_decode_headers.php');
        $this->assertStringContainsString('JitIconvMime::invokeDecodeHeaders', $src);
        $this->assertStringNotContainsString(
            'iconv_mime_decode_headers() is not lowered for JIT/AOT',
            $src
        );
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/iconv_mime_decode_headers.c');
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
        $bin = sys_get_temp_dir().'/iconv_hdr_'.getmypid().'_'.md5($src);
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
