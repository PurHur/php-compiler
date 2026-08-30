<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: openssl_pkey_new($options) runtime options array (#35866 leftover of #34015).
 *
 * @see php-src ext/openssl/openssl.c PHP_FUNCTION(openssl_pkey_new)
 *
 * @group aot-lint
 */
final class Issue35866OpensslPkeyNewRuntimeOptionsAotTest extends TestCase
{
    private const EXPECTED = "OpenSSLAsymmetricKey 512\n";

    public function testVmRuntimeOptions(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/aot_openssl_pkey_new_runtime_options_35866.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'aot_openssl_pkey_new_runtime_options_35866.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    /**
     * @group llvm
     * @group aot
     */
    public function testAotRuntimeOptionsMatchVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_openssl_pkey_new_runtime_options_35866.php';
        $runtime = new Runtime();
        $code = file_get_contents($src);
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'aot_openssl_pkey_new_runtime_options_35866.php'));
        $vmOut = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $vmOut);

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

    public function testRuntimeOptionsPathPresent(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/ext/openssl/openssl_pkey_new.php');
        $this->assertStringContainsString('generateFromRuntimeOptions', $src);
        $this->assertStringNotContainsString(
            'options must be compile-time null/?array for JIT/AOT in this compiler build (issue #34015)',
            $src
        );
        $jit = (string) file_get_contents(dirname(__DIR__, 2).'/ext/openssl/JitOpensslPkeyNew.php');
        $this->assertStringContainsString('generateFromRuntimeOptions', $jit);
        $helper = (string) file_get_contents(dirname(__DIR__, 2).'/ext/openssl/OpensslPkeyNewJitHelper.php');
        $this->assertStringContainsString('generatePemFromOptions', $helper);
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/openssl_pkey_new.c');
    }
}
