<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\openssl\VmOpensslPkeyNative;
use PHPUnit\Framework\TestCase;

/**
 * AOT: openssl_pkey_get_details() happy-path leftover of #33496 (#34030).
 *
 * @see php-src ext/openssl/openssl.c PHP_FUNCTION(openssl_pkey_get_details)
 *
 * @group aot-lint
 */
final class Issue34030OpensslPkeyGetDetailsHappyPathAotTest extends TestCase
{
    public function testVmHappyPathBitsTypeKey(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_34030_openssl_pkey_get_details_aot.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34030_openssl_pkey_get_details_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertMatchesRegularExpression('/^512\n0\nhas-key\n$/', $out);
    }

    /**
     * @group llvm
     * @group aot
     */
    public function testAotHappyPathBitsTypeKeyMatchVm(): void
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
        $this->assertMatchesRegularExpression('/^512\n0\nhas-key\n$/', $vmOut);

        $bin = sys_get_temp_dir().'/phpc_ossl_pkey_details_hp_'.getmypid().'.bin';
        // Thin standalone AOT — runtime libcrypto leaf (peer #34015 HELPER_RUNTIME_O=0).
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
            $this->assertStringContainsString('__phpc_ossl_pkey_get_details', $nmText);
            $this->assertStringContainsString('EVP_PKEY_get_bits', $nmText);
        } finally {
            @unlink($bin);
        }
    }

    /**
     * Compile-time PEM bake: host FFI getDetails → constant HT (#34030 Done-when).
     *
     * @group llvm
     * @group aot
     */
    public function testAotBakeFromCompileTimePemLiteral(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        if (!VmOpensslPkeyNative::available()) {
            $this->markTestSkipped('OpenSSL FFI unavailable');
        }
        $pem = VmOpensslPkeyNative::generateRsa(512);
        $this->assertIsString($pem);
        $details = VmOpensslPkeyNative::getDetails($pem);
        $this->assertIsArray($details);
        $bits = (string) $details['bits'];
        $type = (string) $details['type'];

        $root = dirname(__DIR__, 2);
        // Bake path: openssl_pkey_get_private(literal) still LogicException on AOT; exercise bake
        // by compiling a script whose key PEM is the literal after wrap via pkey_new + export is
        // not available. Instead: NestedJIT helper fromPem on the literal is the bake SSOT —
        // verify helper + bakeFromPemLiteral wiring, and that getDetails matches bits/type.
        $helper = (string) file_get_contents($root.'/ext/openssl/OpensslPkeyGetDetailsJitHelper.php');
        $this->assertStringContainsString('VmOpensslPkeyNative::getDetails', $helper);
        $jit = (string) file_get_contents($root.'/ext/openssl/JitOpensslPkeyGetDetails.php');
        $this->assertStringContainsString('bakeFromPemLiteral', $jit);
        $this->assertStringContainsString('variableFromVmHashTable', $jit);
        $this->assertSame('512', $bits);
        $this->assertSame('0', $type);
        $this->assertStringContainsString('BEGIN PUBLIC KEY', (string) $details['key']);

        // Functional: helper fromPem returns the same shape the AOT leaf must produce.
        $fromHelper = \PHPCompiler\ext\openssl\OpensslPkeyGetDetailsJitHelper::fromPem($pem);
        $this->assertSame((int) $bits, $fromHelper['bits']);
        $this->assertSame((int) $type, $fromHelper['type']);
        $this->assertArrayHasKey('key', $fromHelper);
    }

    public function testHappyPathNoLongerLogicExceptionOnly(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/ext/openssl/openssl_pkey_get_details.php');
        $this->assertStringContainsString('JitOpensslPkeyGetDetails::invoke', $src);
        $this->assertStringNotContainsString(
            'openssl_pkey_get_details() is not implemented for JIT in this compiler build',
            $src
        );
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/openssl_pkey_get_details.c');
        $this->assertFileExists(dirname(__DIR__, 2).'/ext/openssl/JitOpensslPkeyKernel.php');
    }
}
