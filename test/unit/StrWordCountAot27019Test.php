<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT str_word_count must print word counts (not 0) under helper-runtime (#27019).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(str_word_count)
 *
 * @group llvm
 * @group aot
 */
final class StrWordCountAot27019Test extends TestCase
{
    public function testAotStrWordCountMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_27019_aot_str_word_count.php';
        $bin = '/tmp/phpc_str_word_count_'.getmypid().'.bin';
        // Keep HELPER_RUNTIME_O default (1) — the regression is under cache hit.
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        $expect = "2\n2\n2\n";
        try {
            for ($i = 0; $i < 10; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $runText = implode("\n", $runOut)."\n";
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.$runText);
                $this->assertSame($expect, $runText, 'run '.($i + 1));
            }
        } finally {
            @unlink($bin);
        }
    }
}
