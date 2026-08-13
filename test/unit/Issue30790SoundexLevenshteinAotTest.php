<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: soundex()/levenshtein() must match Zend without segfault (#30790).
 *
 * Root cause: NestedJIT `$s[$i]` / isset($s[$i]) abort under thin AOT (#26794);
 * helpers use strlen/substr like VmMetaphone.
 *
 * php-src: ext/standard/string.c / levenshtein.c
 *
 * @group llvm
 * @group aot
 */
final class Issue30790SoundexLevenshteinAotTest extends TestCase
{
    public function testAotSoundexEuler(): void
    {
        $this->compileAndAssert(
            <<<'PHP'
<?php
echo soundex('Euler'), "\n";
echo soundex(''), "\n";
PHP,
            "E460\n0000\n"
        );
    }

    public function testAotLevenshteinKittenSitting(): void
    {
        $this->compileAndAssert(
            <<<'PHP'
<?php
echo levenshtein('kitten', 'sitting'), "\n";
echo levenshtein('', 'abc'), "\n";
echo levenshtein('abc', 'ab', 2, 1, 1), "\n";
PHP,
            "3\n3\n1\n"
        );
    }

    private function compileAndAssert(string $code, string $expected): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30790_'.getmypid().'_'.mt_rand(1000, 9999).'.php';
        $bin = sys_get_temp_dir().'/phpc_30790_'.getmypid().'_'.mt_rand(1000, 9999).'.bin';
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
