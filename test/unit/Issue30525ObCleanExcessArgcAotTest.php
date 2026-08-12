<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: ob_clean() excess argc → ArgumentCountError at runtime (#30525).
 *
 * Uncaught (no try) — peer #28228 / #30508.
 *
 * php-src: ext/standard/output.c / basic_functions.stub.php (ob_clean arity 0)
 *
 * @group llvm
 * @group aot
 */
final class Issue30525ObCleanExcessArgcAotTest extends TestCase
{
    public function testAotValidArityStillWorks(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30525_ok_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
ob_start();
echo "x";
ob_clean();
echo "y";
echo ob_get_clean(), "\n";
PHP);
        $bin = sys_get_temp_dir().'/phpc_30525_ok_'.getmypid().'.bin';
        $this->compileAndAssertOutput(
            $root,
            $src,
            $bin,
            "y\n"
        );
    }

    public function testAotExcessArgcRaisesArgumentCountError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30525_ex_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30525_ex_'.getmypid().'.bin';
        file_put_contents($src, "<?php\nob_clean('x');\n");
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, 'compile: '.implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertNotSame(0, $runRc, 'should abort');
            $joined = implode("\n", $runOut);
            $this->assertStringContainsString(
                'ob_clean() expects exactly 0 arguments, 1 given',
                $joined
            );
            $this->assertStringContainsString('ArgumentCountError', $joined);
            $this->assertStringNotContainsString('LogicException', $joined);
            $this->assertStringNotContainsString('takes no arguments', $joined);
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }

    public function testAotExcessArgcCatchableUnderTry(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30525_try_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30525_try_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
try {
    ob_clean('x');
    echo "NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
PHP);
        $this->compileAndAssertOutput(
            $root,
            $src,
            $bin,
            "ob_clean() expects exactly 0 arguments, 1 given\n"
        );
    }

    private function compileAndAssertOutput(string $root, string $src, string $bin, string $expected): void
    {
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame($expected, implode("\n", $runOut)."\n", 'run '.($i + 1));
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
