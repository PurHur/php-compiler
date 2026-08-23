<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: openssl_pkey_new() leftover of #6295 (#33530) — TypeError/argc gates.
 *
 * @see php-src ext/openssl/openssl.c PHP_FUNCTION(openssl_pkey_new)
 *
 * @group aot-lint
 */
final class OpensslPkeyNewAotTest extends TestCase
{
    private const EXPECTED = "str-type\nargc\n";

    public function testVmTypeErrorAndArgc(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_33530_openssl_pkey_new_aot.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_33530_openssl_pkey_new_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    /**
     * @group llvm
     * @group aot
     */
    public function testAotTypeErrorAndArgcMatchVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33530_openssl_pkey_new_aot.php';
        $runtime = new Runtime();
        $code = file_get_contents($src);
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_33530_openssl_pkey_new_aot.php'));
        $vmOut = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $vmOut);

        $bin = sys_get_temp_dir().'/phpc_ossl_pkey_new_'.getmypid().'.bin';
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

    public function testCallTypeErrorPathNoLongerLogicExceptionOnly(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/ext/openssl/openssl_pkey_new.php');
        $this->assertStringContainsString('emitTypeErrorAndAbort', $src);
        $this->assertStringContainsString('emitArgumentCountErrorAndAbort', $src);
        $this->assertStringContainsString('JitOpensslPkeyNew::emitRsaKeyObject', $src);
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/openssl_pkey_new.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/openssl_pkey_new_keygen.c');
    }

    public function testVmHappyPathOpenSslAsymmetricKey(): void
    {
        if (!\PHPCompiler\ext\openssl\VmOpensslPkeyNative::available()) {
            $this->markTestSkipped('libcrypto FFI unavailable');
        }
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_34015_openssl_pkey_new_aot.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34015_openssl_pkey_new_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame("OpenSSLAsymmetricKey\nOpenSSLAsymmetricKey\n", $out);
    }

    /**
     * @group llvm
     * @group aot
     */
    public function testAotHappyPathOpenSslAsymmetricKey(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        if (!\PHPCompiler\ext\openssl\VmOpensslPkeyNative::available()) {
            $this->markTestSkipped('libcrypto FFI unavailable');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34015_openssl_pkey_new_aot.php';
        $bin = sys_get_temp_dir().'/phpc_ossl_pkey_new_hp_'.getmypid().'.bin';
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
            $this->assertSame("OpenSSLAsymmetricKey\nOpenSSLAsymmetricKey", implode("\n", $runOut));

            // Runtime leaf linked (not a compile-time constant PEM bake).
            $nmOut = [];
            exec('nm '.escapeshellarg($bin).' 2>/dev/null', $nmOut);
            $nmText = implode("\n", $nmOut);
            $this->assertStringContainsString('__phpc_ossl_pkey_generate_rsa', $nmText);
            // libcrypto keygen must be linked for runtime generation.
            $this->assertStringContainsString('EVP_PKEY_keygen', $nmText);

            $runOut2 = [];
            exec(escapeshellarg($bin).' 2>/dev/null', $runOut2, $runRc2);
            $this->assertSame(0, $runRc2, implode("\n", $runOut2));
            $this->assertSame("OpenSSLAsymmetricKey\nOpenSSLAsymmetricKey", implode("\n", $runOut2));
        } finally {
            @unlink($bin);
        }
    }

    public function testVmConsecutiveKeysDiffer(): void
    {
        if (!\PHPCompiler\ext\openssl\VmOpensslPkeyNative::available()) {
            $this->markTestSkipped('libcrypto FFI unavailable');
        }
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$a = openssl_pkey_new();
$b = openssl_pkey_new();
$ea = $eb = '';
openssl_pkey_export($a, $ea);
openssl_pkey_export($b, $eb);
echo ($ea !== $eb && strlen($ea) > 100) ? 'differ' : 'same';
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'pkey_differ.php'));
        $out = trim((string) ob_get_clean());
        $this->assertSame('differ', $out);
    }
}
