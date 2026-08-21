<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: openssl_get_privatekey() leftover of #20306 (#33507) — TypeError/argc gates.
 *
 * @see php-src ext/openssl/openssl.c PHP_FUNCTION(openssl_get_privatekey)
 *
 * @group aot-lint
 */
final class OpensslGetPrivatekeyAotTest extends TestCase
{
    private const EXPECTED = "null-type\nargc\n";

    public function testVmTypeErrorAndArgc(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_33507_openssl_get_privatekey_aot.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_33507_openssl_get_privatekey_aot.php'));
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
        $src = $root.'/test/repro/issue_33507_openssl_get_privatekey_aot.php';
        $runtime = new Runtime();
        $code = file_get_contents($src);
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_33507_openssl_get_privatekey_aot.php'));
        $vmOut = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $vmOut);

        $bin = sys_get_temp_dir().'/phpc_ossl_get_privkey_'.getmypid().'.bin';
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
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/ext/openssl/openssl_get_privatekey.php');
        $this->assertStringContainsString('emitTypeErrorAndAbort', $src);
        $this->assertStringContainsString('expects 1 or 2 arguments', $src);
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/openssl_get_privatekey.c');
    }
}
