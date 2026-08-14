<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: nl2br() must match Zend without segfault (#30813).
 *
 * Root cause: NestedJIT `$s[$i]` abort under thin AOT (#26794);
 * helpers use strlen/substr/ord recursive walk like VmChunkSplit (#30859).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(nl2br)
 *
 * @group llvm
 * @group aot
 */
final class Issue30813Nl2brAotTest extends TestCase
{
    public function testAotNl2brXhtmlLfCrAndCrlf(): void
    {
        // Use shell_exec (raw bytes) — PHP exec() line-splits and drops CR before LF.
        $this->compileAndAssertRaw(
            <<<'PHP'
<?php
echo nl2br("a\nb", true), "\n";
echo nl2br("a\nb", false), "\n";
echo nl2br("a\r\nb", false), "\n";
echo nl2br("a\rb", false), "\n";
echo nl2br("plain", true), "\n";
PHP,
            "a<br />\nb\na<br>\nb\na<br>\r\nb\na<br>\rb\nplain\n"
        );
    }

    private function compileAndAssertRaw(string $code, string $expected): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30813_'.getmypid().'_'.mt_rand(1000, 9999).'.php';
        $bin = sys_get_temp_dir().'/phpc_30813_'.getmypid().'_'.mt_rand(1000, 9999).'.bin';
        file_put_contents($src, $code);
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, 'compile: '.implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $raw = shell_exec(escapeshellarg($bin).' 2>&1');
            $this->assertNotNull($raw, 'shell_exec returned null');
            $this->assertSame($expected, $raw);
            // Repeat — heap corruption can be intermittent (#23842 mindset).
            $raw2 = shell_exec(escapeshellarg($bin).' 2>&1');
            $this->assertSame($expected, $raw2);
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
