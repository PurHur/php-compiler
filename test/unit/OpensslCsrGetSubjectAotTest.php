<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: openssl_csr_get_subject() leftover of #6421 (#32692).
 *
 * @see php-src ext/openssl/xp.c PHP_FUNCTION(openssl_csr_get_subject)
 *
 * @group aot-lint
 */
final class OpensslCsrGetSubjectAotTest extends TestCase
{
    public function testVmGetSubjectPem(): void
    {
        if (!\PHPCompiler\ext\openssl\VmOpensslCsrNative::available()) {
            $this->markTestSkipped('libcrypto FFI unavailable');
        }
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_32692_openssl_csr_get_subject_aot.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_32692_openssl_csr_get_subject_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame("1\ntest\nUS\ntest\nfalse\n", $out);
    }

    /**
     * @group llvm
     * @group aot
     */
    public function testAotGetSubjectPemMatchVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        if (!\PHPCompiler\ext\openssl\VmOpensslCsrNative::available()) {
            $this->markTestSkipped('libcrypto FFI unavailable');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_32692_openssl_csr_get_subject_aot.php';
        $runtime = new Runtime();
        $code = file_get_contents($src);
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_32692_openssl_csr_get_subject_aot.php'));
        $vmOut = (string) ob_get_clean();
        $this->assertSame("1\ntest\nUS\ntest\nfalse\n", $vmOut);

        $bin = sys_get_temp_dir().'/phpc_ossl_csr_subject_'.getmypid().'.bin';
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
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/ext/openssl/openssl_csr_get_subject.php');
        $this->assertStringNotContainsString('not implemented for JIT', $src);
        $this->assertStringContainsString('JitOpensslX509::csrGetSubject', $src);
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/openssl_csr_get_subject.c');
    }
}
