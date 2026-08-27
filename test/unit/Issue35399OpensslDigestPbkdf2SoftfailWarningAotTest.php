<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: openssl_digest()/openssl_pbkdf2() unknown-algo softfail emits Zend-shaped E_WARNING (#35399).
 *
 * @see php-src ext/openssl/openssl.c PHP_FUNCTION(openssl_digest) / openssl_pbkdf2
 *
 * @group aot-lint
 */
final class Issue35399OpensslDigestPbkdf2SoftfailWarningAotTest extends TestCase
{
    /**
     * @group llvm
     * @group aot
     */
    public function testAotBakeSoftfailEmitsZendShapedWarning(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_35399_openssl_digest_pbkdf2_softfail_warning_aot.php';
        $bin = sys_get_temp_dir().'/phpc_ossl_digest_softfail_35399_'.getmypid().'.bin';
        $outFile = sys_get_temp_dir().'/phpc_ossl_digest_softfail_35399_'.getmypid().'.out';
        $errFile = sys_get_temp_dir().'/phpc_ossl_digest_softfail_35399_'.getmypid().'.err';
        // Do not set PHP_COMPILER_HELPER_RUNTIME_O=0 — that drops StringTriggerError
        // bodies and silences softfail E_WARNING on thin AOT (#35382 / #35399).
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            // File-backed stderr — pipe+2>&1 can drop unflushed fprintf from
            // __phpc_stderr_print_cli_error (#21300 / glibc full buffering).
            $cmd = escapeshellarg($bin)
                .' >'.escapeshellarg($outFile)
                .' 2>'.escapeshellarg($errFile);
            exec($cmd, $ignored, $runRc);
            $stdout = (string) file_get_contents($outFile);
            $stderr = (string) file_get_contents($errFile);
            $this->assertSame(0, $runRc, "stdout=\n{$stdout}\nstderr=\n{$stderr}");
            $this->assertSame("false\nfalse\n", $stdout);
            $this->assertStringContainsString(
                'openssl_digest(): Unknown digest algorithm',
                $stderr
            );
            $this->assertStringContainsString(
                'openssl_pbkdf2(): Unknown digest algorithm',
                $stderr
            );
        } finally {
            @unlink($bin);
            @unlink($outFile);
            @unlink($errFile);
        }
    }

    public function testBakeSoftfailWired(): void
    {
        $digest = (string) file_get_contents(dirname(__DIR__, 2).'/ext/openssl/JitOpensslDigest.php');
        $pbkdf2 = (string) file_get_contents(dirname(__DIR__, 2).'/ext/openssl/JitOpensslPbkdf2.php');
        $this->assertStringContainsString('unknownDigestSoftfail', $digest);
        $this->assertStringContainsString('JitBuiltinWarning::emit', $digest);
        $this->assertStringContainsString('openssl_digest(): Unknown digest algorithm', $digest);
        $this->assertStringContainsString('#35399', $digest);
        $this->assertStringContainsString('JitBuiltinWarning::emit', $pbkdf2);
        $this->assertStringContainsString('openssl_pbkdf2(): Unknown digest algorithm', $pbkdf2);
        $this->assertStringContainsString('#35399', $pbkdf2);
        $callSite = (string) file_get_contents(dirname(__DIR__, 2).'/ext/openssl/openssl_pbkdf2.php');
        $this->assertStringContainsString('JitBuiltinWarning::emit', $callSite);
        $this->assertStringContainsString('UNKNOWN_DIGEST_WARNING', $callSite);
        $this->assertStringContainsString('#35399', $callSite);
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/openssl_digest.c');
    }
}
