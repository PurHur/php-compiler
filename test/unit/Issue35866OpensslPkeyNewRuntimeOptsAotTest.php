<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: openssl_pkey_new($opts) runtime options array (#35866 leftover of #34015).
 *
 * @see php-src ext/openssl/openssl.c PHP_FUNCTION(openssl_pkey_new)
 *
 * @group aot-lint
 */
final class Issue35866OpensslPkeyNewRuntimeOptsAotTest extends TestCase
{
    public function testVmRuntimeOptsClassAndBits(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_35866_openssl_pkey_new_runtime_opts.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_35866_openssl_pkey_new_runtime_opts.php'));
        $out = (string) ob_get_clean();
        $this->assertSame("OpenSSLAsymmetricKey 512\n", $out);
    }

    /**
     * @group llvm
     * @group aot
     */
    public function testAotRuntimeOptsMatchVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_35866_openssl_pkey_new_runtime_opts.php';
        $runtime = new Runtime();
        $code = file_get_contents($src);
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_35866_openssl_pkey_new_runtime_opts.php'));
        $vmOut = (string) ob_get_clean();
        $this->assertSame("OpenSSLAsymmetricKey 512\n", $vmOut);

        $bin = sys_get_temp_dir().'/phpc_ossl_pkey_new_rt_'.getmypid().'.bin';
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

    public function testNoLongerThrowsLogicExceptionOnUnfoldableOptions(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/ext/openssl/openssl_pkey_new.php');
        $this->assertStringContainsString('generateFromRuntimeOptions', $src);
        $this->assertStringNotContainsString(
            'options must be compile-time null/?array for JIT/AOT',
            $src
        );
        $jit = (string) file_get_contents(dirname(__DIR__, 2).'/ext/openssl/JitOpensslPkeyNew.php');
        $this->assertStringContainsString('generateFromRuntimeOptions', $jit);
        $this->assertStringContainsString('readOptLong', $jit);
    }
}
