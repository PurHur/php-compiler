<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: chunk_split() must match Zend without segfault (#30859 / re-#26992).
 *
 * Root cause: NestedJIT string-index / isset-length helpers abort under thin AOT;
 * helpers use strlen/substr like VmConvertUu (#30811).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(chunk_split)
 *
 * @group llvm
 * @group aot
 */
final class Issue30859ChunkSplitAotTest extends TestCase
{
    public function testAotChunkSplitMatchesZend(): void
    {
        $this->compileAndAssert(
            <<<'PHP'
<?php
echo chunk_split("abcd", 2, ":"), "\n";
echo chunk_split("abcdef", 2, ":"), "\n";
echo chunk_split("hi", 1, "-"), "\n";
PHP,
            "ab:cd:\nab:cd:ef:\nh-i-\n"
        );
    }

    private function compileAndAssert(string $code, string $expected): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30859_'.getmypid().'_'.mt_rand(1000, 9999).'.php';
        $bin = sys_get_temp_dir().'/phpc_30859_'.getmypid().'_'.mt_rand(1000, 9999).'.bin';
        file_put_contents($src, $code);
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, 'compile: '.implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, 'run rc='.$runRc.' out='.implode("\n", $runOut));
            $this->assertSame($expected, implode("\n", $runOut).([] === $runOut ? '' : "\n"));
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
