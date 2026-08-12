<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: unary math + pi() excess argc → ArgumentCountError at runtime (#30534).
 *
 * php-src: ext/standard/math.c / basic_functions.stub.php
 *
 * @group llvm
 * @group aot
 */
final class Issue30534MathUnaryExcessArgcAotTest extends TestCase
{
    public function testAotValidArityStillWorks(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30534_ok_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
// Arity-only smoke — values already covered elsewhere; #30534 is ArgumentCountError.
pi();
sqrt(4.0);
sin(0.0);
asinh(0.0);
deg2rad(0.0);
log10(100.0);
echo "ok\n";
PHP);
        $bin = sys_get_temp_dir().'/phpc_30534_ok_'.getmypid().'.bin';
        $this->compileAndAssertOutput($root, $src, $bin, "ok\n");
    }

    public function testAotExcessArgcRaisesArgumentCountError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        foreach ([
            'pi' => [
                'code' => "<?php\npi(1);\n",
                'needle' => 'pi() expects exactly 0 arguments, 1 given',
            ],
            'sqrt' => [
                'code' => "<?php\nsqrt(4, 1);\n",
                'needle' => 'sqrt() expects exactly 1 argument, 2 given',
            ],
            'sin' => [
                'code' => "<?php\nsin(0, 1);\n",
                'needle' => 'sin() expects exactly 1 argument, 2 given',
            ],
            'deg2rad' => [
                'code' => "<?php\ndeg2rad(1, 2);\n",
                'needle' => 'deg2rad() expects exactly 1 argument, 2 given',
            ],
            'log10' => [
                'code' => "<?php\nlog10(10, 1);\n",
                'needle' => 'log10() expects exactly 1 argument, 2 given',
            ],
        ] as $label => $case) {
            $src = sys_get_temp_dir().'/phpc_30534_ex_'.$label.'_'.getmypid().'.php';
            $bin = sys_get_temp_dir().'/phpc_30534_ex_'.$label.'_'.getmypid().'.bin';
            file_put_contents($src, $case['code']);
            $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
                .escapeshellarg($root.'/bin/compile.php')
                .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
            exec($compile, $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, $label.' compile: '.implode("\n", $compileOut));
            $this->assertFileExists($bin);
            try {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertNotSame(0, $runRc, $label.' should abort');
                $joined = implode("\n", $runOut);
                $this->assertStringContainsString($case['needle'], $joined, $label);
                $this->assertStringContainsString('ArgumentCountError', $joined, $label);
                $this->assertStringNotContainsString('LogicException', $joined, $label);
            } finally {
                @unlink($src);
                @unlink($bin);
            }
        }
    }

    public function testAotExcessArgcCatchableUnderTry(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30534_try_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30534_try_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
try {
    pi(1);
    echo "NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
try {
    sqrt(4, 1);
    echo "NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
PHP);
        $this->compileAndAssertOutput(
            $root,
            $src,
            $bin,
            "pi() expects exactly 0 arguments, 1 given\n"
            ."sqrt() expects exactly 1 argument, 2 given\n"
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
