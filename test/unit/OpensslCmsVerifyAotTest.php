<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: openssl_cms_verify() leftover of #6592 (#33464).
 *
 * @see php-src ext/openssl/openssl.c PHP_FUNCTION(openssl_cms_verify)
 *
 * @group aot-lint
 */
final class OpensslCmsVerifyAotTest extends TestCase
{
    private const EXPECTED = "true|true|content-ok|false\n";
    private const FIXTURE_DIR = '/tmp/phpc_cms_verify_33464';

    private static function ensureSignedFixture(): void
    {
        $root = dirname(__DIR__, 2);
        $cert = file_get_contents($root.'/test/fixtures/openssl/pkcs7_test_cert.pem');
        $key = file_get_contents($root.'/test/fixtures/openssl/pkcs7_test_key.pem');
        self::assertNotFalse($cert);
        self::assertNotFalse($key);
        @mkdir(self::FIXTURE_DIR);
        $msg = self::FIXTURE_DIR.'/msg.txt';
        $signed = self::FIXTURE_DIR.'/signed.cms';
        file_put_contents($msg, "hello cms\n");
        $ok = \PHPCompiler\ext\openssl\VmOpensslCmsNative::sign(
            $msg,
            $signed,
            $cert,
            $key,
            [],
            \PHPCompiler\ext\openssl\OpensslConstants::OPENSSL_CMS_BINARY,
            \PHPCompiler\ext\openssl\OpensslConstants::OPENSSL_ENCODING_SMIME
        );
        self::assertTrue($ok, 'failed to create CMS signed fixture for #33464');
        self::assertFileExists($signed);
    }

    public function testVmVerifyParsesFixture(): void
    {
        if (!\PHPCompiler\ext\openssl\VmOpensslCmsNative::available()) {
            $this->markTestSkipped('libcrypto FFI unavailable');
        }
        self::ensureSignedFixture();
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_33464_openssl_cms_verify_aot.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_33464_openssl_cms_verify_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    /**
     * @group llvm
     * @group aot
     */
    public function testAotVerifyMatchVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        if (!\PHPCompiler\ext\openssl\VmOpensslCmsNative::available()) {
            $this->markTestSkipped('libcrypto FFI unavailable');
        }
        self::ensureSignedFixture();
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33464_openssl_cms_verify_aot.php';
        $runtime = new Runtime();
        $code = file_get_contents($src);
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_33464_openssl_cms_verify_aot.php'));
        $vmOut = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $vmOut);

        $bin = sys_get_temp_dir().'/phpc_ossl_cms_verify_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=1 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            @unlink(self::FIXTURE_DIR.'/content.txt');
            $runOut = [];
            exec(escapeshellarg($bin).' 2>/dev/null', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame($vmOut, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
            @unlink(self::FIXTURE_DIR.'/content.txt');
        }
    }

    public function testCallNoLongerThrowsJitUnimplemented(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/ext/openssl/openssl_cms_verify.php');
        $this->assertStringNotContainsString('not implemented for JIT', $src);
        $this->assertStringContainsString('JitOpensslX509::cmsVerify', $src);
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/openssl_cms_verify.c');
    }
}
