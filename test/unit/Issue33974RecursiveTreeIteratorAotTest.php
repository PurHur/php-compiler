<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT: RecursiveTreeIterator foreach must verify + match Zend (re-#27584).
 *
 * Mid-invoke RecursiveTreeIteratorBuildRuntime previously let HashTable*Llvm /
 * strval append via BasicBlockHelper into user main while rti_walk_* stayed on
 * the walk ABI → Module.php:180 cross-function basic blocks.
 *
 * php-src: ext/spl/spl_iterators.c — RecursiveTreeIterator
 *
 * @group llvm
 * @group aot
 */
final class Issue33974RecursiveTreeIteratorAotTest extends TestCase
{
    public function testVmSplWrapIteratorsMatchZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_recursive_tree_iterator_module_verify.php';
        $zend = [];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zend, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zend));
        $want = implode("\n", $zend)."\n";

        $runtime = new Runtime();
        $code = file_get_contents($src);
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'aot_recursive_tree_iterator_module_verify.php'));
        $out = (string) ob_get_clean();
        $this->assertSame($want, $out);
        $this->assertStringContainsString('Tree:\\-Array|', $out);
    }

    public function testAotRecursiveTreeIteratorMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_recursive_tree_iterator_module_verify.php';
        $bin = sys_get_temp_dir().'/phpc_rti_33974_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        $expected = [];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $expected, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $expected));
        $want = implode("\n", $expected)."\n";
        $this->assertStringContainsString('Tree:\\-Array|', $want);
        try {
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame($want, implode("\n", $runOut)."\n", 'run '.($i + 1));
            }
        } finally {
            @unlink($bin);
        }
    }
}
