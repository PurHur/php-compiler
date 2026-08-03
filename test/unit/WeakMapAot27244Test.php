<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT execute guard for WeakMap object-key offsetGet/offsetSet (#27244).
 *
 * ArrayAccess dim fetch compiles every ArrayAccess candidate (incl. ArrayObject);
 * ArrayObjectJitHelper must pass HashTableReadLlvm::$superglobalName.
 *
 * php-src: Zend/zend_weakrefs.c — WeakMap offset handlers
 *
 * @group llvm
 * @group aot
 */
final class WeakMapAot27244Test extends TestCase
{
    public function testAotWeakMapObjectKeyStoreFetch(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_weakmap_offset_get_27244.php';
        $bin = sys_get_temp_dir().'/phpc_weakmap_27244_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        putenv('PHP_COMPILER_HELPER_RUNTIME_O=0');
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        $expected = [];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $expected, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $expected));
        $want = implode("\n", $expected)."\n";
        try {
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame($want, implode("\n", $runOut)."\n", 'run '.($i + 1));
            }
        } finally {
            putenv('PHP_COMPILER_HELPER_RUNTIME_O');
            @unlink($bin);
        }
    }

    public function testArrayObjectOffsetGetPassesSuperglobalNull(): void
    {
        $helper = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/VM/ArrayObjectJitHelper.php'
        );
        $this->assertMatchesRegularExpression(
            '/readValueBoxKeyToValueBox\(\s*\$context,\s*\$ht,\s*\$boxedKey,\s*null\s*\)/',
            $helper
        );
    }
}
