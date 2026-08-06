<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT execute guard for array_chunk() nested json_encode (#27074 / #27182).
 *
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_chunk)
 * Root cause (#27182): NestedJIT json_encode lost nested HT type tags and emitted
 * quote("") / toInt 0; restore foreach-on-Variable nested encoding (peer #27074).
 *
 * @group llvm
 * @group aot
 */
final class ArrayChunkAot27074Test extends TestCase
{
    public function testAotArrayChunkExecute(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_array_chunk_27182_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
echo json_encode(array_chunk([1, 2, 3, 4, 5], 2)), "\n";
$p = array_chunk(['a' => 1, 'b' => 2, 'c' => 3], 2, true);
$n = 0;
foreach ($p as $chunk) {
    ++$n;
}
echo $n, "\n";
foreach ($p as $chunk) {
    foreach ($chunk as $k => $v) {
        echo $k, '=', $v, ';';
    }
    echo "\n";
}
PHP);
        $bin = sys_get_temp_dir().'/phpc_array_chunk_27182_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            for ($i = 0; $i < 10; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame(
                    "[[1,2],[3,4],[5]]\n2\na=1;b=2;\nc=3;\n",
                    implode("\n", $runOut)."\n"
                );
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
