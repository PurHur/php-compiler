<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: gc_enabled / restore_error_handler / restore_exception_handler excess argc → ArgumentCountError (#30653).
 *
 * php-src: ext/standard/basic_functions.c
 *
 * @group llvm
 * @group aot
 */
final class Issue30653GcRestoreExcessArgcAotTest extends TestCase
{
    public function testAotValidArityStillWorks(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30653_ok_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
echo gc_enabled() ? '1' : '0', "\n";
echo restore_error_handler() ? '1' : '0', "\n";
echo restore_exception_handler() ? '1' : '0', "\n";
PHP);
        $bin = sys_get_temp_dir().'/phpc_30653_ok_'.getmypid().'.bin';
        $this->compileAndAssertOutput($root, $src, $bin, "1\n1\n1\n");
    }

    public function testAotExcessArgcRaisesArgumentCountError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        foreach ([
            'gc_enabled' => [
                'code' => "<?php\ngc_enabled(1);\n",
                'needle' => 'gc_enabled() expects exactly 0 arguments, 1 given',
            ],
            'restore_error_handler' => [
                'code' => "<?php\nrestore_error_handler(1);\n",
                'needle' => 'restore_error_handler() expects exactly 0 arguments, 1 given',
            ],
            'restore_exception_handler' => [
                'code' => "<?php\nrestore_exception_handler(1);\n",
                'needle' => 'restore_exception_handler() expects exactly 0 arguments, 1 given',
            ],
        ] as $label => $case) {
            $src = sys_get_temp_dir().'/phpc_30653_ex_'.$label.'_'.getmypid().'.php';
            $bin = sys_get_temp_dir().'/phpc_30653_ex_'.$label.'_'.getmypid().'.bin';
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
        $src = sys_get_temp_dir().'/phpc_30653_try_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30653_try_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
try {
    gc_enabled(1);
    echo "gc_enabled NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'gc_enabled ', $e->getMessage(), "\n";
}
try {
    restore_error_handler(1);
    echo "restore_error_handler NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'restore_error_handler ', $e->getMessage(), "\n";
}
try {
    restore_exception_handler(1);
    echo "restore_exception_handler NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'restore_exception_handler ', $e->getMessage(), "\n";
}
PHP);
        $this->compileAndAssertOutput(
            $root,
            $src,
            $bin,
            "gc_enabled gc_enabled() expects exactly 0 arguments, 1 given\n"
            ."restore_error_handler restore_error_handler() expects exactly 0 arguments, 1 given\n"
            ."restore_exception_handler restore_exception_handler() expects exactly 0 arguments, 1 given\n"
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
