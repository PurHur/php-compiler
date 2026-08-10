<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT execute guard for basename()/dirname() (#26905).
 *
 * php-src: ext/standard/basename.c / ext/standard/dir.c
 *
 * @group llvm
 * @group aot
 */
final class BasenameDirnameAot26905Test extends TestCase
{
    public function testAotBasenameDirnameExecuteOk(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_basename_26905_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
echo basename("/a/b.txt"), "\n", dirname("/a/b.txt"), "\n";
echo basename("/a/b.txt", ".txt"), "\n";
$p = "/x/" . "y.z";
echo basename($p), "\n", dirname($p), "\n";
PHP);
        $bin = sys_get_temp_dir().'/phpc_basename_26905_'.getmypid().'.bin';
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        $expected = "b.txt\n/a\nb\ny.z\n/x\n";
        try {
            for ($i = 0; $i < 5; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame($expected, implode("\n", $runOut)."\n", 'run '.($i + 1));
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }

    /**
     * basename($path, null) soft-null coerce under AOT (#29705; php-src basename.c Z_PARAM_STR).
     * DEP display under thin AOT is a separate runtime gap (same as strlen(null) peers); assert value.
     */
    public function testAotBasenameSuffixNullSoftDep(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_basename_29705_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
echo basename('/tmp/foo.txt', null), "\n";
echo basename('/tmp/foo.txt', '.txt'), "\n";
PHP);
        $bin = sys_get_temp_dir().'/phpc_basename_29705_'.getmypid().'.bin';
        $compile = 'PHP_COMPILER_PROFILE=8.4 PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        $expected = "foo.txt\nfoo\n";
        try {
            for ($i = 0; $i < 5; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame($expected, implode("\n", $runOut)."\n", 'run '.($i + 1));
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
