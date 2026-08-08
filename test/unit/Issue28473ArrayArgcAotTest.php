<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: array_filter/reduce/walk excess argc → ArgumentCountError at runtime (#28473).
 *
 * php-src: ext/standard/array.stub.php
 *
 * @group llvm
 * @group aot
 */
final class Issue28473ArrayArgcAotTest extends TestCase
{
    /**
     * Valid-arity AOT compile still succeeds (runtime array_filter AOT is a separate gap).
     */
    public function testAotValidArityCompiles(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_28473_ok_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
$a = [1, 0, 2];
$f = array_filter($a);
PHP);
        $bin = sys_get_temp_dir().'/phpc_28473_ok_'.getmypid().'.bin';
        try {
            $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
                .escapeshellarg($root.'/bin/compile.php')
                .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
            exec($compile, $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, 'compile: '.implode("\n", $compileOut));
            $this->assertFileExists($bin);
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }

    public function testAotWrongArgcRaisesArgumentCountError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_28473_ex_'.getmypid().'.php';
        foreach ([
            'array_filter_missing' => [
                'code' => "<?php\narray_filter();\n",
                'needle' => 'array_filter() expects at least 1 argument, 0 given',
            ],
            'array_filter_excess' => [
                'code' => "<?php\narray_filter([], null, 0, 1);\n",
                'needle' => 'array_filter() expects at most 3 arguments, 4 given',
            ],
            'array_reduce_missing' => [
                'code' => "<?php\narray_reduce();\n",
                'needle' => 'array_reduce() expects at least 2 arguments, 0 given',
            ],
            'array_reduce_excess' => [
                'code' => "<?php\narray_reduce([], function (\$a, \$b) { return \$a; }, 0, 1);\n",
                'needle' => 'array_reduce() expects at most 3 arguments, 4 given',
            ],
            'array_walk_missing' => [
                'code' => "<?php\narray_walk();\n",
                'needle' => 'array_walk() expects at least 2 arguments, 0 given',
            ],
            'array_walk_one' => [
                'code' => "<?php\n\$a = []; array_walk(\$a);\n",
                'needle' => 'array_walk() expects at least 2 arguments, 1 given',
            ],
            'array_walk_excess' => [
                'code' => "<?php\n\$a = []; array_walk(\$a, function () {}, null, 1);\n",
                'needle' => 'array_walk() expects at most 3 arguments, 4 given',
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
                $this->assertStringNotContainsString('requires one to three', $joined, $name);
                $this->assertStringNotContainsString('requires two or three', $joined, $name);
            } finally {
                @unlink($s);
                @unlink($b);
            }
        }
        @unlink($src);
    }
}
