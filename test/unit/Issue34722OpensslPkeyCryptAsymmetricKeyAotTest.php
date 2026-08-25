<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: openssl_*_encrypt/decrypt accept OpenSSLAsymmetricKey (#34722).
 *
 * @see php-src ext/openssl/openssl.c PHP_FUNCTION(openssl_private_encrypt)
 *
 * @group aot-lint
 */
final class Issue34722OpensslPkeyCryptAsymmetricKeyAotTest extends TestCase
{
    private const EXPECTED = "priv_enc:ok\npub_enc:ok\n";

    public function testVmRoundTripWithKeyObject(): void
    {
        if (!\PHPCompiler\ext\openssl\VmOpensslPkeyNative::available()) {
            $this->markTestSkipped('libcrypto FFI unavailable');
        }
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/aot_openssl_pkey_crypt_asymmetric_key_34722.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'aot_openssl_pkey_crypt_asymmetric_key_34722.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    /**
     * @group llvm
     * @group aot
     */
    public function testAotRoundTripWithKeyObjectMatchVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        if (!\PHPCompiler\ext\openssl\VmOpensslPkeyNative::available()) {
            $this->markTestSkipped('libcrypto FFI unavailable');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_openssl_pkey_crypt_asymmetric_key_34722.php';
        $runtime = new Runtime();
        $code = file_get_contents($src);
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'aot_openssl_pkey_crypt_asymmetric_key_34722.php'));
        $vmOut = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $vmOut);

        $bin = sys_get_temp_dir().'/phpc_ossl_pkey_crypt_'.$this->getName().'_'.getmypid().'.bin';
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

    public function testRuntimePathWiredNotBakeOnly(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/ext/openssl/JitOpensslX509.php');
        $this->assertStringContainsString('asymmetricCryptRuntime', $src);
        $this->assertStringContainsString('JitOpensslPkeyCryptKernel::EVP_PRIVATE_ENCRYPT', $src);
        $this->assertStringContainsString('#34722', $src);
        $this->assertFileExists(dirname(__DIR__, 2).'/ext/openssl/JitOpensslPkeyCryptKernel.php');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/openssl_private_encrypt.c');
    }
}
