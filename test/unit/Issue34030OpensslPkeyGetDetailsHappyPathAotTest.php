<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: openssl_pkey_get_details() happy-path details array (#34030 leftover of #33496).
 *
 * @see php-src ext/openssl/openssl.c PHP_FUNCTION(openssl_pkey_get_details)
 *
 * @group aot-lint
 */
final class Issue34030OpensslPkeyGetDetailsHappyPathAotTest extends TestCase
{
    public function testVmHappyPathShape(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_34030_openssl_pkey_get_details_aot.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34030_openssl_pkey_get_details_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertMatchesRegularExpression('/^512\|0\|pub-ok\n$/', $out);
    }

    /**
     * @group llvm
     * @group aot
     */
    public function testAotHappyPathMatchVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34030_openssl_pkey_get_details_aot.php';
        $runtime = new Runtime();
        $code = file_get_contents($src);
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34030_openssl_pkey_get_details_aot.php'));
        $vmOut = (string) ob_get_clean();
        $this->assertMatchesRegularExpression('/^512\|0\|pub-ok\n$/', $vmOut);

        $bin = sys_get_temp_dir().'/phpc_ossl_pkey_gd_hp_'.getmypid().'.bin';
        // Thin standalone AOT (HELPER_RUNTIME_O=0) — libcrypto leaves, not NestedJIT FFI.
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

            $nmOut = [];
            exec('nm '.escapeshellarg($bin).' 2>/dev/null', $nmOut);
            $nmText = implode("\n", $nmOut);
            $this->assertStringContainsString('__phpc_ossl_pkey_details_bits', $nmText);
            $this->assertStringContainsString('EVP_PKEY_get_bits', $nmText);
        } finally {
            @unlink($bin);
        }
    }

    public function testHappyPathNoLongerLogicExceptionOnly(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/ext/openssl/openssl_pkey_get_details.php');
        $this->assertStringContainsString('JitOpensslPkeyGetDetails::details', $src);
        $this->assertStringNotContainsString(
            'openssl_pkey_get_details() is not implemented for JIT in this compiler build',
            $src
        );
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/openssl_pkey_get_details.c');
        $this->assertFileExists(dirname(__DIR__, 2).'/ext/openssl/JitOpensslPkeyGetDetails.php');
        $this->assertFileExists(dirname(__DIR__, 2).'/ext/openssl/OpensslPkeyGetDetailsJitHelper.php');
        $this->assertFileExists(dirname(__DIR__, 2).'/ext/openssl/JitOpensslPkeyGetDetailsKernel.php');
    }
}
