<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT execute guard for getmypid() (#26944).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(getmypid) / getpid(2)
 *
 * @group llvm
 * @group aot
 */
final class GetmypidAot26944Test extends TestCase
{
    public function testAotGetmypidPositivePid(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_getmypid_26944_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
$p = getmypid();
var_export($p);
echo "\n";
echo $p > 0 ? "ok" : "bad", "\n";
PHP);
        $bin = sys_get_temp_dir().'/phpc_getmypid_26944_'.getmypid().'.bin';
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
                $this->assertMatchesRegularExpression('/^[1-9][0-9]*\nok\n$/', $text, 'run '.($i + 1).': '.$text);
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
