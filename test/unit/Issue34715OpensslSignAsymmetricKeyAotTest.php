<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: openssl_sign() accepts OpenSSLAsymmetricKey (not only PEM strings) (#34715).
 *
 * @see php-src ext/openssl/openssl.c PHP_FUNCTION(openssl_sign)
 *
 * @group aot-lint
 */
final class Issue34715OpensslSignAsymmetricKeyAotTest extends TestCase
{
    public function testVmSignWithKeyObject(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/aot_openssl_sign_asymmetric_key_34715.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'aot_openssl_sign_asymmetric_key_34715.php'));
        $out = (string) ob_get_clean();
        $this->assertSame("ok\n", $out);
    }

    /**
     * @group llvm
     * @group aot
     */
    public function testAotSignWithKeyObjectMatchVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_openssl_sign_asymmetric_key_34715.php';
        $runtime = new Runtime();
        $code = file_get_contents($src);
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'aot_openssl_sign_asymmetric_key_34715.php'));
        $vmOut = (string) ob_get_clean();
        $this->assertSame("ok\n", $vmOut);

        $bin = sys_get_temp_dir().'/phpc_ossl_sign_akey_'.getmypid().'.bin';
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

    public function testSignLowersViaResolvePemStringNotStringOnly(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/ext/openssl/JitOpensslSign.php');
        $this->assertStringContainsString('JitOpensslPkeyGetPublic::resolvePemString', $src);
        $this->assertStringContainsString('#34715', $src);
        $pub = (string) file_get_contents(dirname(__DIR__, 2).'/ext/openssl/JitOpensslPkeyGetPublic.php');
        $this->assertStringContainsString('public static function resolvePemString', $pub);
        $this->assertStringContainsString('TYPE_OBJECT', $pub);
    }
}
