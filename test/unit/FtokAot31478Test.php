<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT execute guard for ftok() (#31478).
 *
 * php-src: ext/standard/ftok.c — PHP_FUNCTION(ftok) / ftok(3)
 *
 * @group llvm
 * @group aot
 */
final class FtokAot31478Test extends TestCase
{
    public function testAotFtokPositiveKey(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_ftok_31478_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
$key = ftok(__FILE__, 't');
var_export(is_int($key));
echo "\n";
echo $key !== -1 ? "ok" : "bad", "\n";
$again = ftok(__FILE__, 't');
echo $again === $key ? "stable" : "drift", "\n";
PHP);
        $bin = sys_get_temp_dir().'/phpc_ftok_31478_'.getmypid().'.bin';
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
                $this->assertSame("true\nok\nstable\n", $text, 'run '.($i + 1).': '.$text);
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
