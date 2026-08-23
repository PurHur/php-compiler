<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: openssl_csr_get_public_key() happy-path leftover of #33514 (#34054).
 *
 * @see php-src ext/openssl/xp.c PHP_FUNCTION(openssl_csr_get_public_key)
 *
 * @group aot-lint
 */
final class Issue34054OpensslCsrGetPublicKeyHappyPathAotTest extends TestCase
{
    private const EXPECTED = "OpenSSLAsymmetricKey\n512\nbad-false\n";

    public function testVmHappyPathClassBitsAndBad(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_34054_openssl_csr_get_public_key_happy_aot.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34054_openssl_csr_get_public_key_happy_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    /**
     * @group llvm
     * @group aot
     */
    public function testAotBakeCsrPemLiteralMatchVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34054_openssl_csr_get_public_key_happy_aot.php';
        $runtime = new Runtime();
        $code = file_get_contents($src);
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34054_openssl_csr_get_public_key_happy_aot.php'));
        $vmOut = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $vmOut);

        $bin = sys_get_temp_dir().'/phpc_ossl_csr_gp_hp_'.getmypid().'.bin';
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
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/ext/openssl/openssl_csr_get_public_key.php');
        $this->assertStringContainsString('JitOpensslCsrGetPublicKey::invoke', $src);
        $this->assertStringNotContainsString(
            'openssl_csr_get_public_key() is not implemented for JIT in this compiler build',
            $src
        );
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/openssl_csr_get_public_key.c');
        $jit = (string) file_get_contents(dirname(__DIR__, 2).'/ext/openssl/JitOpensslCsrGetPublicKey.php');
        $this->assertStringContainsString('bakeFromCsrPemLiteral', $jit);
        $this->assertStringContainsString('VmOpensslCsrNative::getPublicKeyPem', $jit);
    }
}
