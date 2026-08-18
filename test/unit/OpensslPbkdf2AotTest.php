<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: openssl_pbkdf2() (#32410, #6488 JIT leftover).
 *
 * @see php-src ext/openssl/openssl.c PHP_FUNCTION(openssl_pbkdf2)
 *
 * @group aot-lint
 */
final class OpensslPbkdf2AotTest extends TestCase
{
    public function testCallNoLongerThrowsJitUnimplemented(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/ext/openssl/openssl_pbkdf2.php');
        $this->assertStringNotContainsString('not implemented for JIT', $src);
        $this->assertStringContainsString('__compiler_openssl_pbkdf2', $src);
        $this->assertStringContainsString('OpensslPbkdf2Runtime::ensureLinked', $src);
        $runtime = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Builtin/OpensslPbkdf2Runtime.php');
        $this->assertStringContainsString('__compiler_hash', $runtime);
        $this->assertStringContainsString('__phpc_ossl_hmac', $runtime);
        $this->assertStringNotContainsString("lookupFunction('PKCS5_PBKDF2_HMAC')", $runtime);
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/openssl_pbkdf2.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/ext/openssl/OpensslPbkdf2JitHelper.php');
    }

    public function testVmPbkdf2Rfc6070Shape(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_openssl_pbkdf2_aot.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_openssl_pbkdf2_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertVmShape($out);
    }

    /**
     * @group llvm
     * @group aot
     */
    public function testAotPbkdf2MatchVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_openssl_pbkdf2_aot.php';
        $runtime = new Runtime();
        $code = file_get_contents($src);
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_openssl_pbkdf2_aot.php'));
        $vmOut = (string) ob_get_clean();
        $this->assertVmShape($vmOut);

        $bin = sys_get_temp_dir().'/phpc_ossl_pbkdf2_'.getmypid().'.bin';
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

    private function assertVmShape(string $out): void
    {
        $this->assertSame(
            "v1ok\n"
            ."v2ok\n"
            ."v3ok\n"
            ."bool(false)\n"
            ."bool(false)\n"
            ."openssl_pbkdf2(): Argument #3 (\$key_length) must be greater than 0\n",
            $out
        );
    }
}
