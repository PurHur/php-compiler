<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: openssl_seal() leftover of #6523 (#32979).
 *
 * @see php-src ext/openssl/openssl.c PHP_FUNCTION(openssl_seal)
 *
 * @group aot-lint
 */
final class OpensslSealAotTest extends TestCase
{
    private const EXPECTED = "true|sealed-ok|ekeys-ok|iv-ok|true\n";

    public function testVmSealWritesEnvelope(): void
    {
        if (!\PHPCompiler\ext\openssl\VmOpensslSealNative::available()) {
            $this->markTestSkipped('libcrypto FFI unavailable');
        }
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_32979_openssl_seal_aot.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_32979_openssl_seal_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    /**
     * @group llvm
     * @group aot
     */
    public function testAotSealShapeMatchVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        if (!\PHPCompiler\ext\openssl\VmOpensslSealNative::available()) {
            $this->markTestSkipped('libcrypto FFI unavailable');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_32979_openssl_seal_aot.php';
        $runtime = new Runtime();
        $code = file_get_contents($src);
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_32979_openssl_seal_aot.php'));
        $vmOut = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $vmOut);

        $bin = sys_get_temp_dir().'/phpc_ossl_seal_'.getmypid().'.bin';
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
            // Shape matches VM; sealed bytes differ across compiles (RAND_bytes).
            $this->assertSame($vmOut, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }

    public function testCallNoLongerThrowsJitUnimplemented(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/ext/openssl/openssl_seal.php');
        $this->assertStringNotContainsString('not implemented for JIT', $src);
        $this->assertStringContainsString('JitOpensslX509::seal', $src);
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/openssl_seal.c');
    }
}
