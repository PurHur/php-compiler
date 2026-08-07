<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: str_increment/str_decrement excess argc → ArgumentCountError at runtime (#28679).
 *
 * Uncaught (no try) — peer #28228 / #28691.
 *
 * php-src: ext/standard/string.c / basic_functions.stub.php
 *
 * @group llvm
 * @group aot
 */
final class Issue28679StrIncdecExcessArgcAotTest extends TestCase
{
    public function testAotValidArityStillWorks(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_28679_ok_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
echo str_increment('a'), "\n";
echo str_decrement('b'), "\n";
PHP);
        $bin = sys_get_temp_dir().'/phpc_28679_ok_'.getmypid().'.bin';
        $this->compileAndAssertOutput($root, $src, $bin, "b\na\n");
    }

    public function testAotExcessArgcRaisesArgumentCountError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_28679_ex_'.getmypid().'.php';
        foreach ([
            'str_increment_excess' => [
                'code' => "<?php\nstr_increment('a', 'x');\n",
                'needle' => 'str_increment() expects exactly 1 argument, 2 given',
            ],
            'str_increment_zero' => [
                'code' => "<?php\nstr_increment();\n",
                'needle' => 'str_increment() expects exactly 1 argument, 0 given',
            ],
            'str_decrement_excess' => [
                'code' => "<?php\nstr_decrement('b', 'x');\n",
                'needle' => 'str_decrement() expects exactly 1 argument, 2 given',
            ],
            'str_decrement_zero' => [
                'code' => "<?php\nstr_decrement();\n",
                'needle' => 'str_decrement() expects exactly 1 argument, 0 given',
            ],
        ] as $name => $case) {
            $s = $src.'.'.$name.'.php';
            $b = $src.'.'.$name.'.bin';
            file_put_contents($s, $case['code']);
            $compile = 'PHP_COMPILER_PROFILE=8.4 PHP_COMPILER_HELPER_RUNTIME_O=0 '
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
        $compile = 'PHP_COMPILER_PROFILE=8.4 PHP_COMPILER_HELPER_RUNTIME_O=0 '
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
