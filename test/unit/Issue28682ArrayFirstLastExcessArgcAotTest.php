<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: array_first/array_last excess argc → ArgumentCountError at runtime (#28682).
 *
 * Uncaught (no try) — peer #28228 / #28679.
 *
 * php-src: ext/standard/array.c / array.stub.php
 *
 * @group llvm
 * @group aot
 */
final class Issue28682ArrayFirstLastExcessArgcAotTest extends TestCase
{
    public function testAotValidArityStillWorks(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_28682_ok_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
echo array_first([10, 20]), "\n";
echo array_last([10, 20]), "\n";
PHP);
        $bin = sys_get_temp_dir().'/phpc_28682_ok_'.getmypid().'.bin';
        $this->compileAndAssertOutput($root, $src, $bin, "10\n20\n");
    }

    public function testAotExcessArgcRaisesArgumentCountError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_28682_ex_'.getmypid().'.php';
        foreach ([
            'array_first_excess' => [
                'code' => "<?php\narray_first([1], 2);\n",
                'needle' => 'array_first() expects exactly 1 argument, 2 given',
            ],
            'array_first_zero' => [
                'code' => "<?php\narray_first();\n",
                'needle' => 'array_first() expects exactly 1 argument, 0 given',
            ],
            'array_last_excess' => [
                'code' => "<?php\narray_last([1], 2);\n",
                'needle' => 'array_last() expects exactly 1 argument, 2 given',
            ],
            'array_last_zero' => [
                'code' => "<?php\narray_last();\n",
                'needle' => 'array_last() expects exactly 1 argument, 0 given',
            ],
        ] as $name => $case) {
            $s = $src.'.'.$name.'.php';
            $b = $src.'.'.$name.'.bin';
            file_put_contents($s, $case['code']);
            $compile = 'PHP_COMPILER_PROFILE=8.5 PHP_COMPILER_HELPER_RUNTIME_O=0 '
                .escapeshellarg(PHP_BINARY).' '
                .escapeshellarg($root.'/bin/compile.php')
                .' -o '.escapeshellarg($b).' '.escapeshellarg($s).' 2>&1';
            exec($compile, $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, $name.' compile: '.implode("\n", $compileOut));
            $this->assertFileExists($b, $name);
            try {
                $runOut = [];
                exec(escapeshellarg($b).' 2>&1', $runOut, $runRc);
                $this->assertNotSame(0, $runRc, $name.' should abort');
                $joined = implode("\n", $runOut);
                $this->assertStringContainsString($case['needle'], $joined, $name);
                $this->assertStringContainsString('ArgumentCountError', $joined, $name);
                $this->assertStringNotContainsString('LogicException', $joined, $name);
                $this->assertStringNotContainsString('in this compiler build', $joined, $name);
            } finally {
                @unlink($s);
                @unlink($b);
            }
        }
    }

    private function compileAndAssertOutput(string $root, string $src, string $bin, string $expected): void
    {
        $compile = 'PHP_COMPILER_PROFILE=8.5 PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
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
