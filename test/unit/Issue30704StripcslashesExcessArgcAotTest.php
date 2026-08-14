<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: stripcslashes() excess argc → ArgumentCountError (#30704).
 *
 * php-src: ext/standard/string.c
 *
 * @group llvm
 * @group aot
 */
final class Issue30704StripcslashesExcessArgcAotTest extends TestCase
{
    public function testAotExcessArgcCatchableUnderTry(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30704_try_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30704_try_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
try {
    stripcslashes('a', 'x');
    echo "hi NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'hi ', $e->getMessage(), "\n";
}
try {
    stripcslashes();
    echo "lo NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'lo ', $e->getMessage(), "\n";
}
echo ('A' === stripcslashes('\\x41')) ? "ok\n" : "bad\n";
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
                    "hi stripcslashes() expects exactly 1 argument, 2 given\n"
                    ."lo stripcslashes() expects exactly 1 argument, 0 given\n"
                    ."ok\n",
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
