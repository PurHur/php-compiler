<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: openssl_*_{en,de}crypt bake softfail emits Zend-shaped E_WARNING (#35382).
 *
 * @see php-src ext/openssl/openssl.c php_openssl_pkey_from_zval
 *
 * @group aot-lint
 */
final class Issue35382OpensslAsymmetricSoftfailWarningAotTest extends TestCase
{
    /**
     * @group llvm
     * @group aot
     */
    public function testAotBakeSoftfailEmitsZendShapedWarning(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        if (!\PHPCompiler\ext\openssl\VmOpensslPkeyNative::available()) {
            $this->markTestSkipped('libcrypto FFI unavailable');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_35382_openssl_asymmetric_softfail_warning_aot.php';
        $bin = sys_get_temp_dir().'/phpc_ossl_softfail_35382_'.getmypid().'.bin';
        $outFile = sys_get_temp_dir().'/phpc_ossl_softfail_35382_'.getmypid().'.out';
        $errFile = sys_get_temp_dir().'/phpc_ossl_softfail_35382_'.getmypid().'.err';
        // Do not set PHP_COMPILER_HELPER_RUNTIME_O=0 — that drops StringTriggerError
        // bodies and silences softfail E_WARNING on thin AOT (#35382).
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            // File-backed stderr — pipe+2>&1 can drop unflushed fprintf from
            // __phpc_stderr_print_cli_error (#21300 / glibc full buffering).
            $cmd = escapeshellarg($bin)
                .' >'.escapeshellarg($outFile)
                .' 2>'.escapeshellarg($errFile);
            exec($cmd, $ignored, $runRc);
            $stdout = (string) file_get_contents($outFile);
            $stderr = (string) file_get_contents($errFile);
            $this->assertSame(0, $runRc, "stdout=\n{$stdout}\nstderr=\n{$stderr}");
            $this->assertSame("false\nfalse\nfalse\nfalse\n", $stdout);
            $this->assertStringContainsString(
                'openssl_public_encrypt(): key parameter is not a valid public key',
                $stderr
            );
            $this->assertStringContainsString(
                'openssl_private_encrypt(): key param is not a valid private key',
                $stderr
            );
            $this->assertStringContainsString(
                'openssl_private_decrypt(): key parameter is not a valid private key',
                $stderr
            );
            $this->assertStringContainsString(
                'openssl_public_decrypt(): key parameter is not a valid public key',
                $stderr
            );
        } finally {
            @unlink($bin);
            @unlink($outFile);
            @unlink($errFile);
        }
    }

    public function testBakeAndRuntimeSoftfailWired(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/ext/openssl/JitOpensslX509.php');
        $this->assertStringContainsString('asymmetricInvalidKeyWarning', $src);
        $this->assertStringContainsString('JitBuiltinWarning::emit', $src);
        $this->assertStringContainsString('key parameter is not a valid public key', $src);
        $this->assertStringContainsString('key param is not a valid private key', $src);
        $this->assertStringContainsString('#35382', $src);
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/openssl_public_encrypt.c');
    }
}
