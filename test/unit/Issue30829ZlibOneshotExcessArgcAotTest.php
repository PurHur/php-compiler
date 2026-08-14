<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: zlib one-shot excess argc → ArgumentCountError (#30829).
 *
 * php-src: ext/zlib/zlib.c
 *
 * @group llvm
 * @group aot
 */
final class Issue30829ZlibOneshotExcessArgcAotTest extends TestCase
{
    public function testAotExcessArgcCatchableUnderTry(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30829_try_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30829_try_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
try {
    gzcompress('a', -1, ZLIB_ENCODING_DEFLATE, 1);
    echo "hi NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'hi ', $e->getMessage(), "\n";
}
try {
    zlib_encode('a');
    echo "lo NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'lo ', $e->getMessage(), "\n";
}
echo ('hello' === gzuncompress(gzcompress('hello'))) ? "ok\n" : "bad\n";
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
                    "hi gzcompress() expects at most 3 arguments, 4 given\n"
                    ."lo zlib_encode() expects at least 2 arguments, 1 given\n"
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
