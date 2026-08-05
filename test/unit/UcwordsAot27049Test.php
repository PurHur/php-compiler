<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT: ucwords() must match Zend (no segfault) (#27049).
 *
 * php-src: ext/standard/string.c — php_ucwords() / php_ucwords_ex()
 *
 * @group llvm
 * @group aot
 */
final class UcwordsAot27049Test extends TestCase
{
    public function testAotUcwordsMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = '/tmp/phpc_ucwords_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
echo ucwords('hello world'), "\n";
echo ucwords('  hello'), "\n";
echo ucwords('hello-world'), "\n";
echo ucwords('hello-world', '-'), "\n";
echo ucwords(''), "\n";
PHP);
        $bin = '/tmp/phpc_ucwords_'.getmypid().'.bin';
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        $expect = "Hello World\n  Hello\nHello-world\nHello-World\n\n";
        try {
            // Heap corruption class — repeat before claiming fixed (#27049 / AGENTS.md).
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
