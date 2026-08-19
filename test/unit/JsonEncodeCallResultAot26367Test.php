<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: json_encode() on runtime string call results must not SIGSEGV (#26367).
 *
 * @group llvm
 * @group aot
 */
final class JsonEncodeCallResultAot26367Test extends TestCase
{
    public function testVmJsonEncodeCallResultMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $path = $root.'/test/repro/issue_26367_json_encode_call_result_aot.php';
        $zend = [];
        $vm = [];
        exec('php '.escapeshellarg($path).' 2>/dev/null', $zend, $zendRc);
        exec(
            'php '.escapeshellarg($root.'/bin/vm.php').' '.escapeshellarg($path).' 2>/dev/null',
            $vm,
            $vmRc
        );
        self::assertSame(0, $zendRc);
        self::assertSame(0, $vmRc);
        self::assertSame(implode("\n", $zend), implode("\n", $vm));
    }

    public function testAotJsonEncodeCallResultMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_26367_json_encode_call_result_aot.php';
        $bin = sys_get_temp_dir().'/phpc_issue_26367_json_encode_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        self::assertSame(0, $compileRc, implode("\n", $compileOut));
        self::assertFileExists($bin);
        try {
            $zend = [];
            exec('php '.escapeshellarg($src).' 2>/dev/null', $zend, $zendRc);
            self::assertSame(0, $zendRc);
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            self::assertSame(0, $runRc, implode("\n", $runOut));
            self::assertSame(implode("\n", $zend), implode("\n", $runOut));
        } finally {
            @unlink($bin);
        }
    }
}
