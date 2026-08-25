<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: foreach string keys must own a separated copy — HT key borrow UAF (#34635).
 *
 * @see php-src Zend/zend_execute.c ZEND_FE_FETCH_R
 *
 * @group llvm
 * @group aot
 */
final class Issue34635ForeachStringKeyBorrowAotTest extends TestCase
{
    public function testCompileKeyHashtableSeparatesStringKey(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/VM/VmIteratorForeach.php'
        );
        $this->assertStringContainsString('__string__separate', $src);
        $this->assertStringContainsString('#34635', $src);
    }

    public function testAotForeachStringKeysSurviveEmptyBody(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_foreach_string_key_borrow.php';
        $bin = sys_get_temp_dir().'/phpc_34635_fe_'.getmypid().'.bin';
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
            $this->assertSame("a,b,c\n2\n", implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }

    public function testAotArrayObjectForeachPreservesGetArrayCopy(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/maintainer_gap_aot_arrayobject_foreach.php';
        $bin = sys_get_temp_dir().'/phpc_34635_ao_'.getmypid().'.bin';
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
            $this->assertSame("3\na=1\nb=2\nc=3\n2\n", implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
