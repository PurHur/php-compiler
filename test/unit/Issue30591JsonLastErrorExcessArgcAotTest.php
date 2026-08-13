<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: json_last_error / json_last_error_msg excess argc → ArgumentCountError (#30591).
 *
 * php-src: ext/json/json.c / json.stub.php
 *
 * @group llvm
 * @group aot
 */
final class Issue30591JsonLastErrorExcessArgcAotTest extends TestCase
{
    public function testAotValidArityStillWorks(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30591_ok_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
echo json_last_error(), "\n";
echo json_last_error_msg(), "\n";
PHP);
        $bin = sys_get_temp_dir().'/phpc_30591_ok_'.getmypid().'.bin';
        $this->compileAndAssertOutput($root, $src, $bin, "0\nNo error\n");
    }

    public function testAotExcessArgcRaisesArgumentCountError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        foreach ([
            'json_last_error' => [
                'code' => "<?php\njson_last_error('x');\n",
                'needle' => 'json_last_error() expects exactly 0 arguments, 1 given',
            ],
            'json_last_error_msg' => [
                'code' => "<?php\njson_last_error_msg('x');\n",
                'needle' => 'json_last_error_msg() expects exactly 0 arguments, 1 given',
            ],
        ] as $label => $case) {
            $src = sys_get_temp_dir().'/phpc_30591_ex_'.$label.'_'.getmypid().'.php';
            $bin = sys_get_temp_dir().'/phpc_30591_ex_'.$label.'_'.getmypid().'.bin';
            file_put_contents($src, $case['code']);
            $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
                .escapeshellarg($root.'/bin/compile.php')
                .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
            exec($compile, $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, $label.' compile: '.implode("\n", $compileOut));
            $this->assertFileExists($bin);
            try {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertNotSame(0, $runRc, $label.' should abort');
                $joined = implode("\n", $runOut);
                $this->assertStringContainsString($case['needle'], $joined, $label);
                $this->assertStringContainsString('ArgumentCountError', $joined, $label);
                $this->assertStringNotContainsString('LogicException', $joined, $label);
                $this->assertStringNotContainsString('takes no arguments', $joined, $label);
            } finally {
                @unlink($src);
                @unlink($bin);
            }
        }
    }

    public function testAotExcessArgcCatchableUnderTry(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30591_try_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30591_try_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
try {
    json_last_error('x');
    echo "json_last_error NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'json_last_error ', $e->getMessage(), "\n";
}
try {
    json_last_error_msg('x');
    echo "json_last_error_msg NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'json_last_error_msg ', $e->getMessage(), "\n";
}
PHP);
        $this->compileAndAssertOutput(
            $root,
            $src,
            $bin,
            "json_last_error json_last_error() expects exactly 0 arguments, 1 given\n"
            ."json_last_error_msg json_last_error_msg() expects exactly 0 arguments, 1 given\n"
        );
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
