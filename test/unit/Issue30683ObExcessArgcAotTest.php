<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: zero-arg OB builtins excess argc → ArgumentCountError at runtime (#30683).
 *
 * Helper-runtime cache required (Ob* LLVM pulls url_rewriter).
 *
 * @group llvm
 * @group aot
 */
final class Issue30683ObExcessArgcAotTest extends TestCase
{
    public function testAotExcessArgcRaisesArgumentCountError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        foreach ([
            'ob_list_handlers' => 'ob_list_handlers() expects exactly 0 arguments, 1 given',
            'ob_get_contents' => 'ob_get_contents() expects exactly 0 arguments, 1 given',
            'ob_get_length' => 'ob_get_length() expects exactly 0 arguments, 1 given',
        ] as $fn => $needle) {
            $src = sys_get_temp_dir().'/phpc_30683_ex_'.$fn.'_'.getmypid().'.php';
            $bin = sys_get_temp_dir().'/phpc_30683_ex_'.$fn.'_'.getmypid().'.bin';
            file_put_contents($src, "<?php\n{$fn}(1);\n");
            $compile = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg($root.'/bin/compile.php')
                .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
            exec($compile, $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, $fn.' compile: '.implode("\n", $compileOut));
            $this->assertFileExists($bin);
            try {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertNotSame(0, $runRc, $fn.' should abort');
                $joined = implode("\n", $runOut);
                $this->assertStringContainsString($needle, $joined, $fn);
                $this->assertStringContainsString('ArgumentCountError', $joined, $fn);
                $this->assertStringNotContainsString('LogicException', $joined, $fn);
                $this->assertStringNotContainsString('takes no arguments', $joined, $fn);
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
        $src = sys_get_temp_dir().'/phpc_30683_try_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30683_try_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
try {
    ob_list_handlers(1);
    echo "ob_list_handlers NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'ob_list_handlers ', $e->getMessage(), "\n";
}
try {
    ob_get_contents(1);
    echo "ob_get_contents NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'ob_get_contents ', $e->getMessage(), "\n";
}
try {
    ob_get_length(1);
    echo "ob_get_length NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'ob_get_length ', $e->getMessage(), "\n";
}
PHP);
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(
                "ob_list_handlers ob_list_handlers() expects exactly 0 arguments, 1 given\n"
                ."ob_get_contents ob_get_contents() expects exactly 0 arguments, 1 given\n"
                ."ob_get_length ob_get_length() expects exactly 0 arguments, 1 given\n",
                implode("\n", $runOut)."\n"
            );
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
