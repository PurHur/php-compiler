<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: wordwrap() must match Zend without segfault (#30812).
 *
 * Root cause: NestedJIT `$s[$i]` / isset($s[$i]) abort under thin AOT (#26794);
 * helpers use strlen/substr like VmSoundex (#30790) / VmConvertUu (#30811).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(wordwrap)
 *
 * @group llvm
 * @group aot
 */
final class Issue30812WordwrapAotTest extends TestCase
{
    public function testAotWordwrapCutAndSpaces(): void
    {
        $this->compileAndAssert(
            <<<'PHP'
<?php
echo wordwrap('abc def ghi', 3, '|', true), "\n";
echo wordwrap('hello world foo', 5, '|', false), "\n";
echo wordwrap('verylongword', 5, '|', true), "\n";
echo wordwrap('', 5, '|', false), "\n";
PHP,
            "abc|def|ghi\nhello|world|foo\nveryl|ongwo|rd\n\n"
        );
    }

    private function compileAndAssert(string $code, string $expected): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30812_'.getmypid().'_'.mt_rand(1000, 9999).'.php';
        $bin = sys_get_temp_dir().'/phpc_30812_'.getmypid().'_'.mt_rand(1000, 9999).'.bin';
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
