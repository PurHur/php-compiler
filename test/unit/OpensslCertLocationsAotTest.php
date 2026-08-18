<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: openssl_get_cert_locations() (#32388, #6560 JIT leftover).
 *
 * @see php-src ext/openssl/openssl.c PHP_FUNCTION(openssl_get_cert_locations)
 *
 * @group aot-lint
 */
final class OpensslCertLocationsAotTest extends TestCase
{
    public function testVmCertLocations(): void
    {
        if (!\PHPCompiler\ext\openssl\VmOpensslConfigNative::available()) {
            $this->markTestSkipped('libcrypto FFI unavailable');
        }
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_openssl_cert_locations_aot.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_openssl_cert_locations_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertVmShape($out);
    }

    /**
     * @group llvm
     * @group aot
     */
    public function testAotCertLocationsMatchVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        if (!\PHPCompiler\ext\openssl\VmOpensslConfigNative::available()) {
            $this->markTestSkipped('libcrypto FFI unavailable');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_openssl_cert_locations_aot.php';
        $runtime = new Runtime();
        $code = file_get_contents($src);
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_openssl_cert_locations_aot.php'));
        $vmOut = (string) ob_get_clean();
        $this->assertVmShape($vmOut);

        $bin = sys_get_temp_dir().'/phpc_ossl_certs_'.getmypid().'.bin';
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

    public function testCallNoLongerThrowsJitUnimplemented(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/ext/openssl/openssl_get_cert_locations.php');
        $this->assertStringNotContainsString('not implemented for JIT', $src);
        $jit = (string) file_get_contents(dirname(__DIR__, 2).'/ext/openssl/JitOpensslMethods.php');
        $this->assertStringContainsString('function certLocations', $jit);
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/openssl_cert_locations.c');
    }

    private function assertVmShape(string $out): void
    {
        $this->assertMatchesRegularExpression(
            '/^array:1\n'
            .'default_cert_file:1\n'
            .'default_cert_file_env:1\n'
            .'default_cert_dir:1\n'
            .'default_cert_dir_env:1\n'
            .'default_private_dir:1\n'
            .'default_default_cert_area:1\n'
            .'ini_cafile:1\n'
            .'ini_capath:1\n'
            .'env_file:1\n'
            .'env_dir:1\n'
            .'file_ne:1\n'
            .'dir_ne:1\n$/',
            $out
        );
    }
}
