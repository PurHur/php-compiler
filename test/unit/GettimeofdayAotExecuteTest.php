<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: gettimeofday() array + float execute paths (#3208 / #30682 follow-up).
 *
 * php-src: ext/standard/microtime.c — PHP_FUNCTION(gettimeofday)
 *
 * @group llvm
 * @group aot
 */
final class GettimeofdayAotExecuteTest extends TestCase
{
    public function testAotArrayAndFloatPaths(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_gtv_exec_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_gtv_exec_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
echo count(gettimeofday()) === 4 ? "keys\n" : "bad\n";
echo gettimeofday(true) > 946684800 ? "float\n" : "bad\n";
echo array_key_exists('sec', gettimeofday()) ? "sec\n" : "bad\n";
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
                $this->assertSame("keys\nfloat\nsec\n", implode("\n", $runOut)."\n", 'run '.$i);
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
