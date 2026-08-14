<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: error_get_last / error_clear_last excess argc → ArgumentCountError (#30674).
 *
 * php-src: ext/standard/error.c
 *
 * @group llvm
 * @group aot
 */
final class Issue30674ErrorLastExcessArgcAotTest extends TestCase
{
    public function testAotValidArityStillWorks(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30674_ok_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
echo error_get_last() === null ? '1' : '0', "\n";
error_clear_last();
echo error_get_last() === null ? '1' : '0', "\n";
PHP);
        $bin = sys_get_temp_dir().'/phpc_30674_ok_'.getmypid().'.bin';
        $this->compileAndAssertOutput($root, $src, $bin, "1\n1\n");
    }

    public function testAotExcessArgcRaisesArgumentCountError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        foreach ([
            'error_get_last' => [
                'code' => "<?php\nerror_get_last(1);\n",
                'needle' => 'error_get_last() expects exactly 0 arguments, 1 given',
            ],
            'error_clear_last' => [
                'code' => "<?php\nerror_clear_last(1);\n",
                'needle' => 'error_clear_last() expects exactly 0 arguments, 1 given',
            ],
        ] as $label => $case) {
            $src = sys_get_temp_dir().'/phpc_30674_ex_'.$label.'_'.getmypid().'.php';
            $bin = sys_get_temp_dir().'/phpc_30674_ex_'.$label.'_'.getmypid().'.bin';
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
                $this->assertStringNotContainsString('takes no arguments', $joined, $label);
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
        $src = sys_get_temp_dir().'/phpc_30674_try_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30674_try_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
try {
    error_get_last(1);
    echo "error_get_last NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'error_get_last ', $e->getMessage(), "\n";
}
try {
    error_clear_last(1);
    echo "error_clear_last NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'error_clear_last ', $e->getMessage(), "\n";
}
PHP);
        $this->compileAndAssertOutput(
            $root,
            $src,
            $bin,
            "error_get_last error_get_last() expects exactly 0 arguments, 1 given\n"
            ."error_clear_last error_clear_last() expects exactly 0 arguments, 1 given\n"
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
