<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: shuffle/natsort/natcasesort excess argc → ArgumentCountError (#30523).
 *
 * Uncaught (no try) — peer #28228 / #30455.
 *
 * php-src: ext/standard/array.c
 *
 * @group llvm
 * @group aot
 */
final class Issue30523ShuffleNatsortExcessArgcAotTest extends TestCase
{
    public function testAotValidArityStillWorks(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30523_ok_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
$a = [1, 2];
natsort($a);
echo implode(',', $a), "\n";
PHP);
        $bin = sys_get_temp_dir().'/phpc_30523_ok_'.getmypid().'.bin';
        $this->compileAndAssertOutput(
            $root,
            $src,
            $bin,
            "1,2\n"
        );
    }

    /**
     * @dataProvider excessArgcScripts
     */
    public function testAotExcessArgcRaisesArgumentCountError(string $script, string $needle): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30523_ex_'.getmypid().'_'.md5($needle).'.php';
        $bin = sys_get_temp_dir().'/phpc_30523_ex_'.getmypid().'_'.md5($needle).'.bin';
        file_put_contents($src, $script);
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, 'compile: '.implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertNotSame(0, $runRc, 'should abort');
            $joined = implode("\n", $runOut);
            $this->assertStringContainsString($needle, $joined);
            $this->assertStringContainsString('ArgumentCountError', $joined);
            $this->assertStringNotContainsString('LogicException', $joined);
            $this->assertStringNotContainsString('requires exactly one argument', $joined);
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function excessArgcScripts(): array
    {
        return [
            'shuffle' => [
                "<?php\n\$a = [1];\nshuffle(\$a, 'x');\n",
                'shuffle() expects exactly 1 argument, 2 given',
            ],
            'natsort' => [
                "<?php\n\$a = ['a'];\nnatsort(\$a, 'x');\n",
                'natsort() expects exactly 1 argument, 2 given',
            ],
            'natcasesort' => [
                "<?php\n\$a = ['a'];\nnatcasesort(\$a, 'x');\n",
                'natcasesort() expects exactly 1 argument, 2 given',
            ],
        ];
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
