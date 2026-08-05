<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT: quotemeta() must match Zend (no segfault) (#27011).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(quotemeta)
 *
 * @group llvm
 * @group aot
 */
final class QuotemetaAot27011Test extends TestCase
{
    public function testAotQuotemetaMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = '/tmp/phpc_quotemeta_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
echo quotemeta('a.b'), "\n";
echo quotemeta('$x+y'), "\n";
echo quotemeta('plain'), "\n";
echo quotemeta('.\\+*?[]^()$'), "\n";
PHP);
        $bin = '/tmp/phpc_quotemeta_'.getmypid().'.bin';
        // Prefer helper-runtime cache (refreshed unit.o) — O=0 NestedJIT of string
        // helpers still yields empty under thin AOT on some hosts (#27007 / #27564).
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        $expect = "a\\.b\n\\\$x\\+y\nplain\n\\.\\\\\\+\\*\\?\\[\\]\\^\\(\\)\\$\n";
        try {
            // Heap corruption class — repeat before claiming fixed (#27011 / AGENTS.md).
            for ($i = 0; $i < 10; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $runText = implode("\n", $runOut)."\n";
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.$runText);
                $this->assertSame($expect, $runText, 'run '.($i + 1));
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
