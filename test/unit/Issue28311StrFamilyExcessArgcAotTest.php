<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: string search/span excess argc → ArgumentCountError at runtime (#28311).
 *
 * Uncaught (no try) — peer #28313. Catchable try/catch under AOT still hits a
 * broader Terminator-in-middle IR bug for AndAbort builtins; not scoped here.
 *
 * php-src: ext/standard/string.stub.php / string.c
 *
 * @group llvm
 * @group aot
 */
final class Issue28311StrFamilyExcessArgcAotTest extends TestCase
{
    public function testAotValidArityStillWorks(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_28311_ok_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
echo strcspn('abc', 'b'), "\n";
echo strspn('aaa', 'a'), "\n";
echo substr_count('aaa', 'a'), "\n";
echo stripos('AbC', 'b'), "\n";
echo strripos('AbCb', 'B'), "\n";
echo strrpos('abcb', 'b'), "\n";
PHP);
        $bin = sys_get_temp_dir().'/phpc_28311_ok_'.getmypid().'.bin';
        $this->compileAndAssertOutput(
            $root,
            $src,
            $bin,
            "1\n3\n3\n1\n3\n3\n"
        );
    }

    public function testAotExcessArgcRaisesArgumentCountError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_28311_ex_'.getmypid().'.php';
        foreach ([
            'strcspn' => [
                'code' => "<?php\nstrcspn('abc', 'a', 0, 1, 'x');\n",
                'needle' => 'strcspn() expects at most 4 arguments, 5 given',
            ],
            'strspn' => [
                'code' => "<?php\nstrspn('abc', 'a', 0, 1, 'x');\n",
                'needle' => 'strspn() expects at most 4 arguments, 5 given',
            ],
            'substr_count' => [
                'code' => "<?php\nsubstr_count('aaa', 'a', 0, 1, 'x');\n",
                'needle' => 'substr_count() expects at most 4 arguments, 5 given',
            ],
            'stripos' => [
                'code' => "<?php\nstripos('abc', 'a', 0, 'x');\n",
                'needle' => 'stripos() expects at most 3 arguments, 4 given',
            ],
            'strripos' => [
                'code' => "<?php\nstrripos('abc', 'a', 0, 'x');\n",
                'needle' => 'strripos() expects at most 3 arguments, 4 given',
            ],
            'strrpos' => [
                'code' => "<?php\nstrrpos('abc', 'a', 0, 'x');\n",
                'needle' => 'strrpos() expects at most 3 arguments, 4 given',
            ],
        ] as $name => $case) {
            $s = $src.'.'.$name.'.php';
            $b = $src.'.'.$name.'.bin';
            file_put_contents($s, $case['code']);
            $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
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
