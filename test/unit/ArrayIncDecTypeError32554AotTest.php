<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: array ++/-- is Zend Cannot increment/decrement array (#32554 leftover of #32486).
 *
 * @see php-src Zend/zend_operators.c increment_function / decrement_function
 *
 * @group llvm
 * @group aot
 */
final class ArrayIncDecTypeError32554AotTest extends TestCase
{
    private const EXPECT = "Cannot increment array\n"
        ."Cannot increment array\n"
        ."Cannot increment array\n"
        ."Cannot decrement array\n"
        ."Cannot decrement array\n"
        ."2\n";

    public function testVmArrayIncDecTypeErrorMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_array_incdec_typeerror.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_array_incdec_typeerror.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotArrayIncDecTypeErrorMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_array_incdec_typeerror.php';
        $bin = sys_get_temp_dir().'/phpc_issue_32554_'.getmypid().'.bin';
        $cache = sys_get_temp_dir().'/phpc_issue_32554_cache_'.getmypid();
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
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(self::EXPECT, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }

    public function testAnalyzerListsIncDecEscapeOperands(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Analyzer.php');
        $this->assertStringContainsString('Op\\Expr\\PreInc', $source);
        $this->assertStringContainsString('Op\\Expr\\PostInc', $source);
        $this->assertStringContainsString('Op\\Expr\\PreDec', $source);
        $this->assertStringContainsString('Op\\Expr\\PostDec', $source);
    }
}
