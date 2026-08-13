<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: readfile() optional args + excess argc (#30582).
 *
 * php-src: ext/standard/file.c
 *
 * @group llvm
 * @group aot
 */
final class Issue30582ReadfileOptionalArgsAotTest extends TestCase
{
    public function testAotOptionalFalseNullSucceeds(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30582_ok_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30582_ok_'.getmypid().'.bin';
        $fixture = sys_get_temp_dir().'/phpc_30582_fix_'.getmypid().'.txt';
        file_put_contents($fixture, "hello-30582\n");
        file_put_contents($src, '<?php
$path = '.var_export($fixture, true).';
$n = readfile($path, false, null);
echo "n=", $n, "\n";
');
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        try {
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                // AOT readfile writes file bytes to real stdout before echo.
                $this->assertSame("hello-30582\nn=12\n", implode("\n", $runOut)."\n", 'run '.($i + 1));
            }
        } finally {
            @unlink($src);
            @unlink($bin);
            @unlink($fixture);
        }
    }

    public function testAotExcessArgcCatchableUnderTry(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30582_try_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30582_try_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
try {
    readfile('/tmp/x', false, null, 'extra');
    echo "NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
PHP);
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        try {
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame(
                    "readfile() expects at most 3 arguments, 4 given\n",
                    implode("\n", $runOut)."\n",
                    'run '.($i + 1)
                );
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
