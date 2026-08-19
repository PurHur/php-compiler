<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: assigned/returned hashtable <=> is Zend zend_compare_arrays (#32536 leftover of #32524/#32528).
 *
 * @see php-src Zend/zend_operators.c zend_compare_arrays
 *
 * @group llvm
 * @group aot
 */
final class HashtableRuntimeOrderedCompare32536AotTest extends TestCase
{
    private const EXPECT = "-1\nt\n0\n-1\n-1\nf\n";

    public function testVmRuntimeHashtableOrderedCompareMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_runtime_hashtable_ordered_compare.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_runtime_hashtable_ordered_compare.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotRuntimeHashtableOrderedCompareMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_runtime_hashtable_ordered_compare.php';
        $bin = sys_get_temp_dir().'/phpc_issue_32536_'.getmypid().'.bin';
        $cache = sys_get_temp_dir().'/phpc_issue_32536_cache_'.getmypid();
        @mkdir($cache, 0777, true);
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR='.escapeshellarg($cache).' '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>/dev/null', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(self::EXPECT, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }

    public function testLiteralAndNullBoolShortcutsStillMatch(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_hashtable_ordered_compare.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_hashtable_ordered_compare.php'));
        $out = (string) ob_get_clean();
        $this->assertSame("-1\nt\nt\n0\neq\nneq\neq\n1\n", $out);
    }
}
