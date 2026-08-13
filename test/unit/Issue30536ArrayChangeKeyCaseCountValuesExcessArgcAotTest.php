<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: array_change_key_case/array_count_values excess argc → ArgumentCountError (#30536).
 *
 * php-src: ext/standard/array.c / basic_functions.stub.php
 *
 * @group llvm
 * @group aot
 */
final class Issue30536ArrayChangeKeyCaseCountValuesExcessArgcAotTest extends TestCase
{
    public function testAotValidArityStillWorks(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30536_ok_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
$a = array_change_key_case(['A' => 1], CASE_LOWER);
echo isset($a['a']) ? 'ok' : 'fail', "\n";
$c = array_count_values([1, 1, 2]);
echo $c[1], ',', $c[2], "\n";
PHP);
        $bin = sys_get_temp_dir().'/phpc_30536_ok_'.getmypid().'.bin';
        $this->compileAndAssertOutput($root, $src, $bin, "ok\n2,1\n");
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
        $src = sys_get_temp_dir().'/phpc_30536_ex_'.getmypid().'_'.md5($needle).'.php';
        $bin = sys_get_temp_dir().'/phpc_30536_ex_'.getmypid().'_'.md5($needle).'.bin';
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
            $this->assertStringNotContainsString('compiler build', $joined);
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }

    public function testAotExcessArgcCatchableUnderTry(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30536_try_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30536_try_'.getmypid().'.bin';
        // Flat try/catch (no closure foreach) — peer #30535 AOT catchable path.
        file_put_contents($src, <<<'PHP'
<?php
try {
    array_change_key_case(['A' => 1], CASE_LOWER, 'x');
    echo "NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
try {
    array_count_values([1], 'x');
    echo "NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
PHP);
        $this->compileAndAssertOutput(
            $root,
            $src,
            $bin,
            "array_change_key_case() expects at most 2 arguments, 3 given\n"
            ."array_count_values() expects exactly 1 argument, 2 given\n"
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function excessArgcScripts(): array
    {
        return [
            'array_change_key_case' => [
                "<?php\narray_change_key_case(['A'=>1], CASE_LOWER, 'x');\n",
                'array_change_key_case() expects at most 2 arguments, 3 given',
            ],
            'array_count_values' => [
                "<?php\narray_count_values([1], 'x');\n",
                'array_count_values() expects exactly 1 argument, 2 given',
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
