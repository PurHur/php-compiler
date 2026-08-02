<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT execute guard for fsync()/fdatasync() (#26929).
 *
 * php-src: ext/standard/file.c — PHP_FUNCTION(fsync) / PHP_FUNCTION(fdatasync)
 *
 * @group llvm
 * @group aot
 */
final class FsyncFdatasyncAot26929Test extends TestCase
{
    public function testAotFsyncFdatasyncExecute(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_fsync_26929_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
$path = sys_get_temp_dir() . '/phpc_fsync_' . getmypid() . '.txt';
$fp = fopen($path, 'w');
fwrite($fp, 'x');
echo fsync($fp) ? "true\n" : "false\n";
echo fdatasync($fp) ? "true\n" : "false\n";
fclose($fp);
unlink($path);
PHP);
        $bin = sys_get_temp_dir().'/phpc_fsync_26929_'.getmypid().'.bin';
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
                $this->assertSame("true\ntrue\n", implode("\n", $runOut)."\n");
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
