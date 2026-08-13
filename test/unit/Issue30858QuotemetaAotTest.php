<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: quotemeta() must match Zend without segfault (#30858 / re-#27011).
 *
 * Root cause: NestedJIT string-index / isset-length helpers abort under thin AOT;
 * helpers use strlen/substr like VmChunkSplit (#30859).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(quotemeta)
 *
 * @group llvm
 * @group aot
 */
final class Issue30858QuotemetaAotTest extends TestCase
{
    public function testAotQuotemetaMatchesZend(): void
    {
        $this->compileAndAssert(
            <<<'PHP'
<?php
echo quotemeta("a.b*c"), "\n";
echo quotemeta(".\\+*?[]^()$"), "\n";
echo quotemeta("plain"), "\n";
echo quotemeta(""), "\n";
PHP,
            "a\\.b\\*c\n\\.\\\\\\+\\*\\?\\[\\]\\^\\(\\)\\$\nplain\n\n"
        );
    }

    private function compileAndAssert(string $code, string $expected): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30858_'.getmypid().'_'.mt_rand(1000, 9999).'.php';
        $bin = sys_get_temp_dir().'/phpc_30858_'.getmypid().'_'.mt_rand(1000, 9999).'.bin';
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
