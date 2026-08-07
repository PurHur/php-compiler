<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: excess argc → ArgumentCountError at runtime (#28691).
 *
 * Uncaught (no try) — peer #28228 / #28311.
 *
 * php-src: ext/standard/basic_functions.stub.php / array.stub.php
 *
 * @group llvm
 * @group aot
 */
final class Issue28691TimeUrlArrayExcessArgcAotTest extends TestCase
{
    public function testAotValidArityStillWorks(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_28691_ok_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
$p = parse_url('http://example.com/path');
echo is_array($p) ? $p['host'] : 'fail', "\n";
$a = array_combine(['a'], ['b']);
echo is_array($a) ? $a['a'] : 'fail', "\n";
$col = array_column([['k' => 'v']], 'k');
echo is_array($col) ? ($col[0] ?? 'fail') : 'fail', "\n";
PHP);
        $bin = sys_get_temp_dir().'/phpc_28691_ok_'.getmypid().'.bin';
        $this->compileAndAssertOutput(
            $root,
            $src,
            $bin,
            "example.com\nb\nv\n"
        );
    }

    public function testAotExcessArgcRaisesArgumentCountError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_28691_ex_'.getmypid().'.php';
        foreach ([
            'microtime' => [
                'code' => "<?php\nmicrotime(true, 'x');\n",
                'needle' => 'microtime() expects at most 1 argument, 2 given',
            ],
            'sleep' => [
                'code' => "<?php\nsleep(0, 'x');\n",
                'needle' => 'sleep() expects exactly 1 argument, 2 given',
            ],
            'parse_url' => [
                'code' => "<?php\nparse_url('http://a', -1, 'x');\n",
                'needle' => 'parse_url() expects at most 2 arguments, 3 given',
            ],
            'array_combine' => [
                'code' => "<?php\narray_combine([1], [2], 'x');\n",
                'needle' => 'array_combine() expects exactly 2 arguments, 3 given',
            ],
            'http_build_query' => [
                'code' => "<?php\nhttp_build_query([], '', '&', PHP_QUERY_RFC1738, 'x');\n",
                'needle' => 'http_build_query() expects at most 4 arguments, 5 given',
            ],
            'array_column' => [
                'code' => "<?php\narray_column([], 'a', null, 'x');\n",
                'needle' => 'array_column() expects at most 3 arguments, 4 given',
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
