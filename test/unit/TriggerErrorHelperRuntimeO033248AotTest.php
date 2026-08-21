<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * HELPER_RUNTIME_O=0 thin AOT must link __compiler_trigger_error before Type::register NestedJIT (#33248).
 *
 * @group llvm
 * @group aot
 */
final class TriggerErrorHelperRuntimeO033248AotTest extends TestCase
{
    public function testHelloWorldCompilesWithHelperRuntimeO0(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33248_hello_helper_o0.php';
        $bin = sys_get_temp_dir().'/phpc_trigger_33248_'.getmypid().'.bin';
        try {
            $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
                .escapeshellarg(PHP_BINARY).' '
                .escapeshellarg($root.'/bin/compile.php')
                .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
            exec($compile, $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, implode("\n", $compileOut));
            $this->assertFileExists($bin);
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame("hi\n", implode("\n", $runOut)."\n");
        } finally {
            if (is_file($bin)) {
                @unlink($bin);
            }
        }
    }

    public function testDomSetAttributeCompilesWithHelperRuntimeO0(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33248_dom_setattr_helper_o0.php';
        $bin = sys_get_temp_dir().'/phpc_dom_33248_'.getmypid().'.bin';
        try {
            $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
                .escapeshellarg(PHP_BINARY).' '
                .escapeshellarg($root.'/bin/compile.php')
                .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
            exec($compile, $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, implode("\n", $compileOut));
            $this->assertFileExists($bin);
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame("1\n", implode("\n", $runOut)."\n");
        } finally {
            if (is_file($bin)) {
                @unlink($bin);
            }
        }
    }
}
