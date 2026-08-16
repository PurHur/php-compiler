<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: doubleval() excess argc → ArgumentCountError cites doubleval() (#30688).
 *
 * php-src: ext/standard/type.c
 *
 * @group llvm
 * @group aot
 */
final class Issue30688DoublevalExcessArgcAotTest extends TestCase
{
    public function testAotValidArityStillWorks(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30688_ok_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30688_ok_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
echo doubleval('2.5'), "\n";
echo floatval('3.5'), "\n";
PHP);
        $this->compileAndAssertOutput($root, $src, $bin, "2.5\n3.5\n");
    }

    public function testAotExcessArgcRaisesArgumentCountError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30688_ex_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30688_ex_'.getmypid().'.bin';
        file_put_contents($src, "<?php\ndoubleval(1, 1);\n");
        $compile = escapeshellarg(PHP_BINARY).' '
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
                'doubleval() expects exactly 1 argument, 2 given',
                $joined
            );
            $this->assertStringContainsString('ArgumentCountError', $joined);
            $this->assertStringNotContainsString('floatval() expects exactly 1 argument, 2 given', $joined);
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
        $src = sys_get_temp_dir().'/phpc_30688_try_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30688_try_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
try {
    doubleval(1, 1);
    echo "dv NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'dv ', $e->getMessage(), "\n";
}
try {
    doubleval();
    echo "dv0 NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'dv0 ', $e->getMessage(), "\n";
}
try {
    floatval(1, 1);
    echo "fv NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'fv ', $e->getMessage(), "\n";
}
PHP);
        $this->compileAndAssertOutput(
            $root,
            $src,
            $bin,
            "dv doubleval() expects exactly 1 argument, 2 given\n"
            ."dv0 doubleval() expects exactly 1 argument, 0 given\n"
            ."fv floatval() expects exactly 1 argument, 2 given\n"
        );
    }

    private function compileAndAssertOutput(string $root, string $src, string $bin, string $expected): void
    {
        // Use default helper-runtime cache (HELPER_RUNTIME_O=0 currently fails link on
        // __phpc_url_rewriter_apply in this tree; default path is the supported AOT smoke).
        $compile = escapeshellarg(PHP_BINARY).' '
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
