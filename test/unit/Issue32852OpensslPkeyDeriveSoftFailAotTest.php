<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT openssl_pkey_derive soft-fail scalars bake to false (#32852 residual / #26689).
 *
 * @group llvm
 * @group aot
 */
final class Issue32852OpensslPkeyDeriveSoftFailAotTest extends TestCase
{
    public function testPkeyDeriveRecognizesCompileTimeSoftFailScalars(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/openssl/JitOpensslX509.php'
        );
        $this->assertStringContainsString('isCompileTimeSoftFailDeriveKey', $source);
        $this->assertStringContainsString('#26689 / #32852', $source);
    }

    public function testAotSoftFailScalarsMatchZend(): void
    {
        if (!\PHPCompiler\LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_32852_openssl_pkey_derive_soft_fail_aot.php';
        $bin = sys_get_temp_dir().'/phpc_derive_sf_32852_'.getmypid().'.bin';
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));

        $zendOut = [];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $expected = implode("\n", $zendOut)."\n";

        try {
            for ($i = 0; $i < 5; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame($expected, implode("\n", $runOut)."\n", 'run '.($i + 1));
            }
        } finally {
            @unlink($bin);
        }
    }
}
