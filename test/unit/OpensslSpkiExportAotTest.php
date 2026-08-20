<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: openssl_spki_export() leftover of #6423 (#32787).
 *
 * @see php-src ext/openssl/openssl.c PHP_FUNCTION(openssl_spki_export)
 *
 * @group aot-lint
 */
final class OpensslSpkiExportAotTest extends TestCase
{
    private const EXPECTED = "export-ok|false\n";

    public function testVmExportMatchesFixture(): void
    {
        if (!\PHPCompiler\ext\openssl\VmOpensslSpkiNative::available()) {
            $this->markTestSkipped('libcrypto FFI unavailable');
        }
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_32787_openssl_spki_export_aot.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_32787_openssl_spki_export_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    /**
     * @group llvm
     * @group aot
     */
    public function testAotExportMatchVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        if (!\PHPCompiler\ext\openssl\VmOpensslSpkiNative::available()) {
            $this->markTestSkipped('libcrypto FFI unavailable');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_32787_openssl_spki_export_aot.php';
        $runtime = new Runtime();
        $code = file_get_contents($src);
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_32787_openssl_spki_export_aot.php'));
        $vmOut = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $vmOut);

        $bin = sys_get_temp_dir().'/phpc_ossl_spki_export_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=1 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>/dev/null', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame($vmOut, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }

    public function testCallNoLongerThrowsJitUnimplemented(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/ext/openssl/openssl_spki_export.php');
        $this->assertStringNotContainsString('not implemented for JIT', $src);
        $this->assertStringContainsString('JitOpensslX509::spkiExport', $src);
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/openssl_spki_export.c');
    }
}
