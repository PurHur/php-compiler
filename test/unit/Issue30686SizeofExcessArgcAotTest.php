<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: sizeof() excess argc → ArgumentCountError cites sizeof() (#30686).
 *
 * php-src: ext/standard/array.c
 *
 * @group llvm
 * @group aot
 */
final class Issue30686SizeofExcessArgcAotTest extends TestCase
{
    public function testAotValidArityStillWorks(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30686_ok_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30686_ok_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
echo sizeof([1, 2]), "\n";
echo count([1, 2, 3]), "\n";
PHP);
        $this->compileAndAssertOutput($root, $src, $bin, "2\n3\n");
    }

    public function testAotExcessArgcRaisesArgumentCountError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30686_ex_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30686_ex_'.getmypid().'.bin';
        file_put_contents($src, "<?php\nsizeof([1], COUNT_NORMAL, 1);\n");
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
                'sizeof() expects at most 2 arguments, 3 given',
                $joined
            );
            $this->assertStringContainsString('ArgumentCountError', $joined);
            $this->assertStringNotContainsString('count() expects at most 2 arguments, 3 given', $joined);
            $this->assertStringNotContainsString('LogicException', $joined);
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
        $src = sys_get_temp_dir().'/phpc_30686_try_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30686_try_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
try {
    sizeof([1], COUNT_NORMAL, 1);
    echo "sz NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'sz ', $e->getMessage(), "\n";
}
try {
    sizeof();
    echo "sz0 NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'sz0 ', $e->getMessage(), "\n";
}
try {
    count([1], COUNT_NORMAL, 1);
    echo "ct NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'ct ', $e->getMessage(), "\n";
}
PHP);
        $this->compileAndAssertOutput(
            $root,
            $src,
            $bin,
            "sz sizeof() expects at most 2 arguments, 3 given\n"
            ."sz0 sizeof() expects at least 1 argument, 0 given\n"
            ."ct count() expects at most 2 arguments, 3 given\n"
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
