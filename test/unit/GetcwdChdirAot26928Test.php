<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT execute guard for getcwd()/chdir() (#26928).
 *
 * php-src: ext/standard/dir.c — PHP_FUNCTION(getcwd) / PHP_FUNCTION(chdir)
 *
 * @group llvm
 * @group aot
 */
final class GetcwdChdirAot26928Test extends TestCase
{
    public function testAotGetcwdChdirExecuteOkOk(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = '/tmp/phpc_getcwd_26928_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
$cwd = getcwd();
echo is_string($cwd) && $cwd !== "" ? "ok" : "bad";
echo "\n";
echo chdir($cwd) ? "ok" : "bad";
echo "\n";
PHP);
        $bin = '/tmp/phpc_getcwd_26928_'.getmypid().'.bin';
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
                $this->assertSame("ok\nok\n", implode("\n", $runOut)."\n");
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
