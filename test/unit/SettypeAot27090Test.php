<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT: settype() type-name ABI must be `%__string__*` (#27090).
 *
 * php-src: ext/standard/type.c — PHP_FUNCTION(settype)
 *
 * @group llvm
 * @group aot
 */
final class SettypeAot27090Test extends TestCase
{
    public function testAotSettypeIntMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = '/tmp/phpc_settype_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
$x = "12";
settype($x, "int");
var_export($x);
echo "\n";
PHP);
        $bin = '/tmp/phpc_settype_'.getmypid().'.bin';
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        $expect = "12\n";
        try {
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
