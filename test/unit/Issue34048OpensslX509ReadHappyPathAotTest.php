<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: openssl_x509_read() happy-path leftover of #33497 (#34048).
 *
 * @see php-src ext/openssl/xp.c PHP_FUNCTION(openssl_x509_read)
 *
 * @group aot-lint
 */
final class Issue34048OpensslX509ReadHappyPathAotTest extends TestCase
{
    private const EXPECTED = "OpenSSLCertificate\n";

    public function testVmHappyPathClass(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_34048_openssl_x509_read_aot.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34048_openssl_x509_read_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    /**
     * @group llvm
     * @group aot
     */
    public function testAotBakePemLiteralMatchVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34048_openssl_x509_read_aot.php';
        $runtime = new Runtime();
        $code = file_get_contents($src);
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34048_openssl_x509_read_aot.php'));
        $vmOut = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $vmOut);

        $bin = sys_get_temp_dir().'/phpc_ossl_x509_read_hp_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
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

    public function testHappyPathNoLongerLogicExceptionOnly(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/ext/openssl/openssl_x509_read.php');
        $this->assertStringContainsString('JitOpensslX509Read::invoke', $src);
        $this->assertStringNotContainsString(
            'openssl_x509_read() is not implemented for JIT in this compiler build',
            $src
        );
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/openssl_x509_read.c');
        $jit = (string) file_get_contents(dirname(__DIR__, 2).'/ext/openssl/JitOpensslX509Read.php');
        $this->assertStringContainsString('bakeFromPemLiteral', $jit);
        $this->assertStringContainsString('VmOpensslX509Native::normalizeCertificatePem', $jit);
    }
}
