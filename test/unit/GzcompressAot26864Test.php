<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT execute guard for gzcompress/gzuncompress (#26864).
 *
 * php-src: ext/zlib/zlib.c — PHP_FUNCTION(gzcompress) / PHP_FUNCTION(gzuncompress)
 *
 * @group llvm
 * @group aot
 */
final class GzcompressAot26864Test extends TestCase
{
    public function testAotGzcompressGzuncompressRoundTrip(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = '/tmp/phpc_gz_26864_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
$c = gzcompress("hello world", 6);
echo strlen($c), "\n";
echo gzuncompress($c), "\n";
PHP);
        $bin = '/tmp/phpc_gz_26864_'.getmypid().'.bin';
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame("19\nhello world\n", implode("\n", $runOut)."\n");
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
