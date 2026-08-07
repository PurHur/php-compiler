<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: stream_context_set_options wrong argc → ArgumentCountError (#28680).
 *
 * @group llvm
 * @group aot
 */
final class Issue28680StreamContextSetOptionsExcessArgcAotTest extends TestCase
{
    public function testAotHappyPathStillTrue(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_28680_ok_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_28680_ok_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
$ctx = stream_context_create();
var_export(stream_context_set_options($ctx, ['http' => ['method' => 'GET']]));
echo "\n";
PHP);
        $compile = 'PHP_COMPILER_PROFILE=8.4 PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame("true\n", implode("\n", $runOut)."\n");
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }

    public function testAotZeroArgRaisesArgumentCountError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_28680_ex_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_28680_ex_'.getmypid().'.bin';
        file_put_contents($src, "<?php\nstream_context_set_options();\n");
        $compile = 'PHP_COMPILER_PROFILE=8.4 PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertNotSame(0, $runRc);
            $joined = implode("\n", $runOut);
            $this->assertStringContainsString(
                'stream_context_set_options() expects exactly 2 arguments, 0 given',
                $joined
            );
            $this->assertStringContainsString('ArgumentCountError', $joined);
            $this->assertStringNotContainsString('LogicException', $joined);
            $this->assertStringNotContainsString('in this compiler build', $joined);
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
