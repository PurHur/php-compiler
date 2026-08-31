<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mixed packed+string-key foreach no longer drops string keys (#34977).
 *
 * @see php-src Zend/zend_execute.c ZEND_FE_FETCH_R
 * @see php-src ext/spl/spl_array.c
 *
 * @group llvm
 * @group aot
 */
final class Issue34977ArrayIteratorStringKeysAotTest extends TestCase
{
    public function testStringKeyOrdinalSubtractsNextFree(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/VM/VmIteratorForeach.php'
        );
        $this->assertStringContainsString('packedPrefixEnd', $src);
        $this->assertStringContainsString('stringKeyNodeAtOrdinal', $src);
    }

    public function testAotArrayIteratorAndMixedArrayForeach(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34977_arrayiterator_string_keys_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34977_'.getmypid().'.bin';
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
            $this->assertSame(
                "4|0=1;1=2;x=3;2=4;null\n3|0=1;1=2;y=5;\n",
                implode("\n", $runOut)."\n"
            );
        } finally {
            @unlink($bin);
        }
    }
}
