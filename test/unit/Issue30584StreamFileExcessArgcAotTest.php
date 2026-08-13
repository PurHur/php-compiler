<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: stream/file builtins excess argc → ArgumentCountError (#30584).
 *
 * php-src: ext/standard/file.c / streamsfuncs.c
 *
 * @group llvm
 * @group aot
 */
final class Issue30584StreamFileExcessArgcAotTest extends TestCase
{
    public function testAotValidArityStillWorks(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30584_ok_'.getmypid().'.php';
        // stream_context_* only — avoid StreamIoJitHelper ftellargv gap (#20982).
        file_put_contents($src, <<<'PHP'
<?php
$c = stream_context_create(['http' => ['method' => 'GET']]);
stream_context_set_option($c, 'http', 'header', 'X: 1');
$p = stream_context_get_params($c);
echo is_array($p) ? 'ok' : 'fail', "\n";
$c2 = stream_context_create();
echo is_array($c2) || is_resource($c2) || is_object($c2) ? 'ok' : 'fail', "\n";
PHP);
        $bin = sys_get_temp_dir().'/phpc_30584_ok_'.getmypid().'.bin';
        $this->compileAndAssertOutput($root, $src, $bin, "ok\nok\n");
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
        $src = sys_get_temp_dir().'/phpc_30584_ex_'.getmypid().'_'.md5($needle).'.php';
        $bin = sys_get_temp_dir().'/phpc_30584_ex_'.getmypid().'_'.md5($needle).'.bin';
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
        $src = sys_get_temp_dir().'/phpc_30584_try_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30584_try_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
$c = stream_context_create();
try {
    stream_context_create([], [], 'extra');
    echo "NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
try {
    stream_context_set_option($c, 'http', 'method', 'GET', 'extra');
    echo "NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
try {
    stream_context_get_params($c, 'extra');
    echo "NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
PHP);
        $this->compileAndAssertOutput(
            $root,
            $src,
            $bin,
            "stream_context_create() expects at most 2 arguments, 3 given\n"
            ."stream_context_set_option() expects at most 4 arguments, 5 given\n"
            ."stream_context_get_params() expects exactly 1 argument, 2 given\n"
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function excessArgcScripts(): array
    {
        return [
            'stream_context_create' => [
                "<?php\nstream_context_create([],[],'x');\n",
                'stream_context_create() expects at most 2 arguments, 3 given',
            ],
            'stream_context_set_option' => [
                "<?php\n\$c=stream_context_create();\nstream_context_set_option(\$c,'http','method','GET','x');\n",
                'stream_context_set_option() expects at most 4 arguments, 5 given',
            ],
            'stream_context_get_params' => [
                "<?php\n\$c=stream_context_create();\nstream_context_get_params(\$c,'x');\n",
                'stream_context_get_params() expects exactly 1 argument, 2 given',
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
