<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: gettimeofday() excess argc → ArgumentCountError (#30682).
 *
 * php-src: ext/standard/microtime.c
 *
 * Note: valid 0/1-arg gettimeofday() AOT currently fails on master with
 * "Current basic block has no parent function" (AotTest gettimeofday fixture).
 * This guard only asserts the excess-argc catchable path (#30682).
 *
 * @group llvm
 * @group aot
 */
final class Issue30682GettimeofdayExcessArgcAotTest extends TestCase
{
    public function testAotExcessArgcCatchableUnderTry(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30682_try_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30682_try_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
try {
    gettimeofday(false, 1);
    echo "hi NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'hi ', $e->getMessage(), "\n";
}
try {
    gettimeofday(false, 1, 2);
    echo "hi3 NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'hi3 ', $e->getMessage(), "\n";
}
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
                    "hi gettimeofday() expects at most 1 argument, 2 given\n"
                    ."hi3 gettimeofday() expects at most 1 argument, 3 given\n",
                    implode("\n", $runOut)."\n",
                    'run '.$i
                );
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
