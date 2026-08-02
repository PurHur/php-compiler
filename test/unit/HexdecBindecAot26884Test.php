<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT execute guard for hexdec()/bindec() (#26884).
 *
 * php-src: ext/standard/math.c — PHP_FUNCTION(hexdec) / PHP_FUNCTION(bindec)
 *
 * @group llvm
 * @group aot
 */
final class HexdecBindecAot26884Test extends TestCase
{
    public function testAotHexdecBindecExecute(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_hexdec_bindec_26884_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
echo hexdec('ff'), "\n";
echo bindec('1010'), "\n";
PHP);
        $bin = sys_get_temp_dir().'/phpc_hexdec_bindec_26884_'.getmypid().'.bin';
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
                $this->assertSame("255\n10\n", implode("\n", $runOut)."\n");
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
