<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT execute guard for getrusage() isset/keys (#27551).
 *
 * Peer: NestedJIT HashTable return miscompile (#26943 / #27294).
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(getrusage)
 *
 * @group llvm
 * @group aot
 */
final class GetrusageAot27551Test extends TestCase
{
    public function testAotGetrusageUtmeKeyExecute(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_getrusage_27551_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
$r = getrusage();
echo isset($r['ru_utime.tv_sec']) ? 'ok' : 'no';
echo '|';
echo isset($r['ru_maxrss']) ? 'ok' : 'no';
PHP);
        $bin = sys_get_temp_dir().'/phpc_getrusage_27551_'.getmypid().'.bin';
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
                $this->assertSame('ok|ok', implode("\n", $runOut));
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
