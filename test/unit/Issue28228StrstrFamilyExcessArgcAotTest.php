<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: strstr/stristr/strchr excess argc → ArgumentCountError at runtime (#28228).
 *
 * Uncaught (no try) — peer #28311. Catchable try/catch under AOT still hits a
 * broader Terminator-in-middle IR bug for AndAbort builtins; not scoped here.
 *
 * php-src: ext/standard/string.stub.php / string.c
 *
 * @group llvm
 * @group aot
 */
final class Issue28228StrstrFamilyExcessArgcAotTest extends TestCase
{
    public function testAotValidArityStillWorks(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_28228_ok_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
echo strstr('abcdef', 'c'), "\n";
echo strstr('abcdef', 'c', true), "\n";
echo stristr('AbCdEf', 'c'), "\n";
echo strchr('abcdef', 'd'), "\n";
PHP);
        $bin = sys_get_temp_dir().'/phpc_28228_ok_'.getmypid().'.bin';
        $this->compileAndAssertOutput(
            $root,
            $src,
            $bin,
            "cdef\nab\nCdEf\ndef\n"
        );
    }

    public function testAotExcessArgcRaisesArgumentCountError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_28228_ex_'.getmypid().'.php';
        foreach ([
            'strstr' => [
                'code' => "<?php\nstrstr('abcdef', 'c', false, true);\n",
                'needle' => 'strstr() expects at most 3 arguments, 4 given',
            ],
            'stristr' => [
                'code' => "<?php\nstristr('abcdef', 'c', false, true);\n",
                'needle' => 'stristr() expects at most 3 arguments, 4 given',
            ],
            'strchr' => [
                'code' => "<?php\nstrchr('abcdef', 'c', false, true);\n",
                'needle' => 'strchr() expects at most 3 arguments, 4 given',
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
