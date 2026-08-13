<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: user_error() excess argc → ArgumentCountError "at most 2" (#30690).
 *
 * php-src: Zend/zend_builtin_functions.c
 *
 * @group llvm
 * @group aot
 */
final class Issue30690UserErrorExcessArgcAotTest extends TestCase
{
    public function testAotValidArityStillWorks(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30690_ok_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30690_ok_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
error_reporting(0);
echo user_error('x', E_USER_NOTICE) ? '1' : '0', "\n";
PHP);
        $this->compileAndAssertOutput($root, $src, $bin, "1\n");
    }

    public function testAotExcessArgcRaisesArgumentCountError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30690_ex_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30690_ex_'.getmypid().'.bin';
        file_put_contents($src, "<?php\nuser_error('x', E_USER_NOTICE, 1);\n");
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
            $this->assertStringContainsString(
                'user_error() expects at most 2 arguments, 3 given',
                $joined
            );
            $this->assertStringContainsString('ArgumentCountError', $joined);
            $this->assertStringNotContainsString('LogicException', $joined);
            $this->assertStringNotContainsString('expects at least 1 argument, 3 given', $joined);
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
        $src = sys_get_temp_dir().'/phpc_30690_try_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30690_try_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
try {
    user_error('x', E_USER_NOTICE, 1);
    echo "ue NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'ue ', $e->getMessage(), "\n";
}
try {
    user_error();
    echo "ue0 NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'ue0 ', $e->getMessage(), "\n";
}
PHP);
        $this->compileAndAssertOutput(
            $root,
            $src,
            $bin,
            "ue user_error() expects at most 2 arguments, 3 given\n"
            ."ue0 user_error() expects at least 1 argument, 0 given\n"
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
