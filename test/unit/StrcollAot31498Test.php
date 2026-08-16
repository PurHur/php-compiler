<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT execute guard for strcoll() after LibcExtern always-on drop (#31498).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(strcoll)
 *
 * @group llvm
 * @group aot
 */
final class StrcollAot31498Test extends TestCase
{
    public function testAotStrcollComparisonSigns(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_strcoll_31498_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
echo strcoll('a', 'b'), "\n";
echo strcoll('b', 'a'), "\n";
echo strcoll('a', 'a'), "\n";
PHP);
        $bin = sys_get_temp_dir().'/phpc_strcoll_31498_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $text = implode("\n", $runOut)."\n";
                $this->assertSame("-1\n1\n0\n", $text, 'run '.($i + 1).': '.$text);
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
