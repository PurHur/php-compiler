<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT: strrev() must match Zend (no segfault) (#27007).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(strrev)
 *
 * @group llvm
 * @group aot
 */
final class StrrevAot27007Test extends TestCase
{
    public function testAotStrrevMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = '/tmp/phpc_strrev_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
echo strrev('ab'), "\n";
echo strrev('php-compiler'), "\n";
echo strrev(''), "\n";
echo strrev('x'), "\n";
PHP);
        $bin = '/tmp/phpc_strrev_'.getmypid().'.bin';
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        $expect = "ba\nrelipmoc-php\n\nx\n";
        try {
            // Heap corruption class — repeat before claiming fixed (#27007 / AGENTS.md).
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
