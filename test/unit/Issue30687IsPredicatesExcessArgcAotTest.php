<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: is_scalar / is_numeric / is_resource excess argc → ArgumentCountError (#30687).
 *
 * php-src: Zend/zend_builtin_functions.c
 *
 * @group llvm
 * @group aot
 */
final class Issue30687IsPredicatesExcessArgcAotTest extends TestCase
{
    public function testAotValidArityStillWorks(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30687_ok_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
echo is_scalar(1) ? '1' : '0', "\n";
echo is_numeric('1') ? '1' : '0', "\n";
echo is_resource(1) ? '1' : '0', "\n";
PHP);
        $bin = sys_get_temp_dir().'/phpc_30687_ok_'.getmypid().'.bin';
        $this->compileAndAssertOutput($root, $src, $bin, "1\n1\n0\n");
    }

    public function testAotExcessArgcRaisesArgumentCountError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        foreach ([
            'is_scalar' => [
                'code' => "<?php\nis_scalar(1, 1);\n",
                'needle' => 'is_scalar() expects exactly 1 argument, 2 given',
            ],
            'is_numeric' => [
                'code' => "<?php\nis_numeric('1', 1);\n",
                'needle' => 'is_numeric() expects exactly 1 argument, 2 given',
            ],
            'is_resource' => [
                'code' => "<?php\nis_resource(1, 1);\n",
                'needle' => 'is_resource() expects exactly 1 argument, 2 given',
            ],
        ] as $label => $case) {
            $src = sys_get_temp_dir().'/phpc_30687_ex_'.$label.'_'.getmypid().'.php';
            $bin = sys_get_temp_dir().'/phpc_30687_ex_'.$label.'_'.getmypid().'.bin';
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
                $this->assertStringNotContainsString('requires exactly one argument', $joined, $label);
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
        $src = sys_get_temp_dir().'/phpc_30687_try_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30687_try_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
try {
    is_scalar(1, 1);
    echo "is_scalar NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'is_scalar ', $e->getMessage(), "\n";
}
try {
    is_numeric('1', 1);
    echo "is_numeric NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'is_numeric ', $e->getMessage(), "\n";
}
try {
    is_resource(1, 1);
    echo "is_resource NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'is_resource ', $e->getMessage(), "\n";
}
PHP);
        $this->compileAndAssertOutput(
            $root,
            $src,
            $bin,
            "is_scalar is_scalar() expects exactly 1 argument, 2 given\n"
            ."is_numeric is_numeric() expects exactly 1 argument, 2 given\n"
            ."is_resource is_resource() expects exactly 1 argument, 2 given\n"
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
                $this->assertSame($expected, implode("\n", $runOut)."\n");
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
