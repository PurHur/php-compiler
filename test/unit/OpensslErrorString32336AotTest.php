<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\Builtin\OpensslSignRuntime;
use PHPUnit\Framework\TestCase;

/**
 * AOT: openssl_error_string() empty queue is false (#32336).
 *
 * @see php-src ext/openssl/openssl.c PHP_FUNCTION(openssl_error_string)
 *
 * @group llvm
 * @group aot
 */
final class OpensslErrorString32336AotTest extends TestCase
{
    public function testVmOpensslErrorStringEmptyQueue(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_32336_openssl_error_string_aot.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_32336_openssl_error_string_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame("false\n", $out);
    }

    public function testAotOpensslErrorStringEmptyQueue(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        if (!OpensslSignRuntime::opensslEvRuntimeAvailable()) {
            $this->markTestSkipped('libcrypto not available for AOT openssl_error_string');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_32336_openssl_error_string_aot.php';
        $bin = sys_get_temp_dir().'/phpc_issue_32336_'.getmypid().'.bin';
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
            $this->assertSame("false\n", implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
