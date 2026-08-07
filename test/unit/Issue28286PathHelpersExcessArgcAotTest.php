<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: basename/dirname/pathinfo excess argc → ArgumentCountError at runtime (#28286).
 *
 * Uncaught (no try) — peer ucwords #28317. Catchable try/catch under AOT still hits a
 * broader Terminator-in-middle IR bug for AndAbort builtins; not scoped to this issue.
 *
 * php-src: ext/standard/file.stub.php
 *
 * @group llvm
 * @group aot
 */
final class Issue28286PathHelpersExcessArgcAotTest extends TestCase
{
    public function testAotValidPathHelpersStillWork(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_28286_ok_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
echo basename('/a/b.txt', '.txt'), "\n";
echo dirname('/a/b.txt'), "\n";
echo pathinfo('/a/b.txt', PATHINFO_FILENAME), "\n";
PHP);
        $bin = sys_get_temp_dir().'/phpc_28286_ok_'.getmypid().'.bin';
        $this->compileAndAssertOutput($root, $src, $bin, "b\n/a\nb\n");
    }

    public function testAotExcessArgcRaisesArgumentCountError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_28286_ex_'.getmypid().'.php';
        // Separate binaries per builtin so the first abort does not mask the others.
        foreach ([
            'basename' => [
                'code' => "<?php\nbasename('/a/b', '.b', true);\n",
                'needle' => 'basename() expects at most 2 arguments, 3 given',
            ],
            'dirname' => [
                'code' => "<?php\ndirname('/a/b', 1, true);\n",
                'needle' => 'dirname() expects at most 2 arguments, 3 given',
            ],
            'pathinfo' => [
                'code' => "<?php\npathinfo('/a/b', PATHINFO_FILENAME, true);\n",
                'needle' => 'pathinfo() expects at most 2 arguments, 3 given',
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
