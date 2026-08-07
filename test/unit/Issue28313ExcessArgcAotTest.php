<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: string/hash helpers excess argc → ArgumentCountError at runtime (#28313).
 *
 * Uncaught (no try) — peer #28286. Catchable try/catch under AOT still hits a
 * broader Terminator-in-middle IR bug for AndAbort builtins; not scoped to this issue.
 *
 * php-src: ext/standard/string.stub.php, crc32.c, basic_functions.stub.php
 *
 * @group llvm
 * @group aot
 */
final class Issue28313ExcessArgcAotTest extends TestCase
{
    public function testAotValidArityStillWorks(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_28313_ok_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
echo str_rot13('ab'), "\n";
echo crc32('foo'), "\n";
echo md5('a'), "\n";
echo sha1('a'), "\n";
echo quoted_printable_decode('a'), "\n";
PHP);
        $bin = sys_get_temp_dir().'/phpc_28313_ok_'.getmypid().'.bin';
        $this->compileAndAssertOutput(
            $root,
            $src,
            $bin,
            "no\n2356372769\n0cc175b9c0f1b6a831c399e269772661\n86f7e437faa5a7fce15d1ddcb9eaeaea377667b8\na\n"
        );
    }

    public function testAotExcessArgcRaisesArgumentCountError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_28313_ex_'.getmypid().'.php';
        foreach ([
            'str_shuffle' => [
                'code' => "<?php\nstr_shuffle('ab', 'x');\n",
                'needle' => 'str_shuffle() expects exactly 1 argument, 2 given',
            ],
            'str_rot13' => [
                'code' => "<?php\nstr_rot13('a', 'x');\n",
                'needle' => 'str_rot13() expects exactly 1 argument, 2 given',
            ],
            'hebrev' => [
                'code' => "<?php\nhebrev('a', 0, 'x');\n",
                'needle' => 'hebrev() expects at most 2 arguments, 3 given',
            ],
            'quoted_printable_decode' => [
                'code' => "<?php\nquoted_printable_decode('a', 'x');\n",
                'needle' => 'quoted_printable_decode() expects exactly 1 argument, 2 given',
            ],
            'crc32' => [
                'code' => "<?php\ncrc32('a', 'x');\n",
                'needle' => 'crc32() expects exactly 1 argument, 2 given',
            ],
            'md5' => [
                'code' => "<?php\nmd5('a', true, 'x');\n",
                'needle' => 'md5() expects at most 2 arguments, 3 given',
            ],
            'sha1' => [
                'code' => "<?php\nsha1('a', true, 'x');\n",
                'needle' => 'sha1() expects at most 2 arguments, 3 given',
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
                $this->assertStringNotContainsString('seed must be', $joined, $name);
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
