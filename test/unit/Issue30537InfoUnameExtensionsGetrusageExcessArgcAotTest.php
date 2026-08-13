<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: php_uname/get_loaded_extensions/getrusage excess argc → ArgumentCountError (#30537).
 *
 * php-src: ext/standard/basic_functions.c / basic_functions.stub.php
 *
 * @group llvm
 * @group aot
 */
final class Issue30537InfoUnameExtensionsGetrusageExcessArgcAotTest extends TestCase
{
    public function testAotValidArityStillWorks(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30537_ok_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
echo php_uname('s') !== '' ? 'ok' : 'fail', "\n";
$ext = get_loaded_extensions();
echo is_array($ext) ? 'ok' : 'fail', "\n";
$ru = getrusage();
echo is_array($ru) || $ru === false ? 'ok' : 'fail', "\n";
PHP);
        $bin = sys_get_temp_dir().'/phpc_30537_ok_'.getmypid().'.bin';
        $this->compileAndAssertOutput($root, $src, $bin, "ok\nok\nok\n");
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
        $src = sys_get_temp_dir().'/phpc_30537_ex_'.getmypid().'_'.md5($needle).'.php';
        $bin = sys_get_temp_dir().'/phpc_30537_ex_'.getmypid().'_'.md5($needle).'.bin';
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
        $src = sys_get_temp_dir().'/phpc_30537_try_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30537_try_'.getmypid().'.bin';
        // Flat try/catch (no closure foreach) — peer #30536 AOT catchable path.
        file_put_contents($src, <<<'PHP'
<?php
try {
    php_uname('s', 'x');
    echo "NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
try {
    get_loaded_extensions(false, 1);
    echo "NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
try {
    getrusage(0, 1);
    echo "NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
PHP);
        $this->compileAndAssertOutput(
            $root,
            $src,
            $bin,
            "php_uname() expects at most 1 argument, 2 given\n"
            ."get_loaded_extensions() expects at most 1 argument, 2 given\n"
            ."getrusage() expects at most 1 argument, 2 given\n"
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function excessArgcScripts(): array
    {
        return [
            'php_uname' => [
                "<?php\nphp_uname('s', 'x');\n",
                'php_uname() expects at most 1 argument, 2 given',
            ],
            'get_loaded_extensions' => [
                "<?php\nget_loaded_extensions(false, 1);\n",
                'get_loaded_extensions() expects at most 1 argument, 2 given',
            ],
            'getrusage' => [
                "<?php\ngetrusage(0, 1);\n",
                'getrusage() expects at most 1 argument, 2 given',
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
