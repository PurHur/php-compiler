<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: openssl_pkey_get_public() happy-path PEM/key → OpenSSLAsymmetricKey (#34038 leftover of #33499).
 *
 * @see php-src ext/openssl/openssl.c PHP_FUNCTION(openssl_pkey_get_public)
 *
 * @group aot-lint
 */
final class Issue34038OpensslPkeyGetPublicHappyPathAotTest extends TestCase
{
    public function testVmHappyPathShape(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_34038_openssl_pkey_get_public_aot.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34038_openssl_pkey_get_public_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame("ok|alias-ok\n", $out);
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
        $src = $root.'/test/repro/issue_34038_openssl_pkey_get_public_aot.php';
        $runtime = new Runtime();
        $code = file_get_contents($src);
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34038_openssl_pkey_get_public_aot.php'));
        $vmOut = (string) ob_get_clean();
        $this->assertSame("ok|alias-ok\n", $vmOut);

        $bin = sys_get_temp_dir().'/phpc_ossl_pkey_gp_hp_'.getmypid().'.bin';
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
            $this->assertStringContainsString('__phpc_ossl_pkey_details_pub', $nmText);
        } finally {
            @unlink($bin);
        }
    }

    public function testHappyPathNoLongerLogicExceptionOnly(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/ext/openssl/openssl_pkey_get_public.php');
        $this->assertStringContainsString('JitOpensslPkeyGetPublic::fromArg', $src);
        $this->assertStringNotContainsString(
            'openssl_pkey_get_public() is not implemented for JIT in this compiler build',
            $src
        );
        $alias = (string) file_get_contents(dirname(__DIR__, 2).'/ext/openssl/openssl_get_publickey.php');
        $this->assertStringContainsString('JitOpensslPkeyGetPublic::fromArg', $alias);
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/openssl_pkey_get_public.c');
        $this->assertFileExists(dirname(__DIR__, 2).'/ext/openssl/JitOpensslPkeyGetPublic.php');
    }
}
