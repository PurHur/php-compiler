<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: get_resource_type() excess argc → ArgumentCountError (#30707).
 *
 * php-src: Zend/zend_builtin_functions.c
 *
 * @group llvm
 * @group aot
 */
final class Issue30707GetResourceTypeExcessArgcAotTest extends TestCase
{
    public function testAotValidArityStillWorks(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30707_ok_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30707_ok_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
$f = fopen('php://memory', 'r');
echo get_resource_type($f), "\n";
fclose($f);
PHP);
        $this->compileAndAssertOutput($root, $src, $bin, "stream\n");
    }

    public function testAotExcessArgcRaisesArgumentCountError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30707_ex_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30707_ex_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
$f = fopen('php://memory', 'r');
get_resource_type($f, 'x');
PHP);
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
                'get_resource_type() expects exactly 1 argument, 2 given',
                $joined
            );
            $this->assertStringContainsString('ArgumentCountError', $joined);
            $this->assertStringNotContainsString('LogicException', $joined);
            $this->assertStringNotContainsString('requires exactly one argument', $joined);
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
        $src = sys_get_temp_dir().'/phpc_30707_try_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30707_try_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
$f = fopen('php://memory', 'r');
try {
    get_resource_type($f, 'x');
    echo "NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
echo get_resource_type($f), "\n";
fclose($f);
PHP);
        $this->compileAndAssertOutput(
            $root,
            $src,
            $bin,
            "get_resource_type() expects exactly 1 argument, 2 given\n"
            ."stream\n"
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
                $this->assertSame($expected, implode("\n", $runOut)."\n");
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
