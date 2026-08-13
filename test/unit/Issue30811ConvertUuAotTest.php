<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: convert_uuencode()/convert_uudecode() must match Zend without segfault (#30811).
 *
 * Root cause: NestedJIT string-index / 256-arm match tables abort under thin AOT;
 * helpers use strlen/substr/ord/chr like VmSoundex (#30790).
 *
 * php-src: ext/standard/uuencode.c
 *
 * @group llvm
 * @group aot
 */
final class Issue30811ConvertUuAotTest extends TestCase
{
    public function testAotConvertUuRoundTrip(): void
    {
        // substr(..., 0, 12) includes the encoded newline; echo adds another → blank line.
        $this->compileAndAssert(
            <<<'PHP'
<?php
echo substr(convert_uuencode('test'), 0, 12), "\n";
echo convert_uudecode(convert_uuencode('test')), "\n";
echo convert_uudecode(convert_uuencode('cat')), "\n";
PHP,
            "\$=&5S=```\n`\n\ntest\ncat\n"
        );
    }

    private function compileAndAssert(string $code, string $expected): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30811_'.getmypid().'_'.mt_rand(1000, 9999).'.php';
        $bin = sys_get_temp_dir().'/phpc_30811_'.getmypid().'_'.mt_rand(1000, 9999).'.bin';
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
