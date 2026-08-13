<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: dechex/decoct/decbin/octdec excess argc → ArgumentCountError at runtime (#30535).
 *
 * php-src: ext/standard/math.c / basic_functions.stub.php
 *
 * @group llvm
 * @group aot
 */
final class Issue30535BaseConvertExcessArgcAotTest extends TestCase
{
    public function testAotValidArityStillWorks(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30535_ok_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
echo dechex(10), "\n";
echo decoct(10), "\n";
echo decbin(10), "\n";
echo octdec('12'), "\n";
PHP);
        $bin = sys_get_temp_dir().'/phpc_30535_ok_'.getmypid().'.bin';
        $this->compileAndAssertOutput($root, $src, $bin, "a\n12\n1010\n10\n");
    }

    public function testAotExcessArgcRaisesArgumentCountError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        foreach ([
            'dechex' => [
                'code' => "<?php\ndechex(10, 1);\n",
                'needle' => 'dechex() expects exactly 1 argument, 2 given',
            ],
            'decoct' => [
                'code' => "<?php\ndecoct(10, 1);\n",
                'needle' => 'decoct() expects exactly 1 argument, 2 given',
            ],
            'decbin' => [
                'code' => "<?php\ndecbin(10, 1);\n",
                'needle' => 'decbin() expects exactly 1 argument, 2 given',
            ],
            'octdec' => [
                'code' => "<?php\noctdec('12', 1);\n",
                'needle' => 'octdec() expects exactly 1 argument, 2 given',
            ],
        ] as $label => $case) {
            $src = sys_get_temp_dir().'/phpc_30535_ex_'.$label.'_'.getmypid().'.php';
            $bin = sys_get_temp_dir().'/phpc_30535_ex_'.$label.'_'.getmypid().'.bin';
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
        $src = sys_get_temp_dir().'/phpc_30535_try_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30535_try_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
try {
    dechex(10, 1);
    echo "NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
try {
    octdec('12', 1);
    echo "NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
PHP);
        $this->compileAndAssertOutput(
            $root,
            $src,
            $bin,
            "dechex() expects exactly 1 argument, 2 given\n"
            ."octdec() expects exactly 1 argument, 2 given\n"
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
