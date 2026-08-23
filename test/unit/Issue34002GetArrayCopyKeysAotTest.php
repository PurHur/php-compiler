<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: ArrayObject/ArrayIterator getArrayCopy preserves keys; ArrayIterator was silent-null (#34002).
 *
 * @see php-src ext/spl/spl_array.c zim_ArrayObject_getArrayCopy / zim_ArrayIterator_getArrayCopy
 *
 * @group llvm
 * @group aot
 */
final class Issue34002GetArrayCopyKeysAotTest extends TestCase
{
    private const EXPECTED = "0,2,3|1,3,4\narr|0,2,3|1,3,4\na,c\n";

    public function testAotGetArrayCopyPreservesKeys(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34002_getarraycopy_keys_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34002_gac_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 PHP_COMPILER_LLVM_ASSERT=1 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(self::EXPECTED, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }

    public function testArrayIteratorProxyRegistered(): void
    {
        $ctx = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Context.php'
        );
        $this->assertStringContainsString("'getArrayCopy',", $ctx);
        $this->assertStringContainsString('#34002', $ctx);
        $ai = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Call/ArrayIteratorMethod.php'
        );
        $this->assertStringContainsString("'getarraycopy'", $ai);
        $helper = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/VM/ArrayObjectJitHelper.php'
        );
        $this->assertStringContainsString('HashTableDuplicateRuntime::duplicate', $helper);
        $this->assertStringContainsString('#34002', $helper);
    }
}
