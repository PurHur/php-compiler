<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: get_extension_funcs() excess argc → ArgumentCountError (#30784).
 *
 * php-src: ext/standard/basic_functions.c
 *
 * Happy-path AOT for get_extension_funcs() has a separate module-verify defect;
 * this guard only covers excess/missing argc (issue done-when).
 *
 * @group llvm
 * @group aot
 */
final class Issue30784GetExtensionFuncsExcessArgcAotTest extends TestCase
{
    public function testAotExcessArgcCatchableUnderTry(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30784_try_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30784_try_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
try {
    get_extension_funcs('standard', 1);
    echo "hi NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'hi ', $e->getMessage(), "\n";
}
try {
    get_extension_funcs();
    echo "lo NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'lo ', $e->getMessage(), "\n";
}
echo "ok\n";
PHP);
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
                $this->assertSame(0, $runRc, 'run '.$i.': '.implode("\n", $runOut));
                $this->assertSame(
                    "hi get_extension_funcs() expects exactly 1 argument, 2 given\n"
                    ."lo get_extension_funcs() expects exactly 1 argument, 0 given\n"
                    ."ok\n",
                    implode("\n", $runOut)."\n",
                    'run '.$i
                );
                $joined = implode("\n", $runOut);
                $this->assertStringNotContainsString('LogicException', $joined);
                $this->assertStringNotContainsString('requires exactly one argument', $joined);
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }

    public function testAotExcessArgcRaisesArgumentCountError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30784_ex_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30784_ex_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
get_extension_funcs('standard', 1);
PHP);
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
                'get_extension_funcs() expects exactly 1 argument, 2 given',
                $joined
            );
            $this->assertStringContainsString('ArgumentCountError', $joined);
            $this->assertStringNotContainsString('LogicException', $joined);
            $this->assertStringNotContainsString('requires exactly one argument', $joined);
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
