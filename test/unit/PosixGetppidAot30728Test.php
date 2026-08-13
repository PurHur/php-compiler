<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT execute guard for posix_getppid() (#30728).
 *
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_getppid) / getppid(2)
 *
 * @group llvm
 * @group aot
 */
final class PosixGetppidAot30728Test extends TestCase
{
    public function testAotPosixGetppidPositiveParentPid(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_posix_getppid_30728_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
$p = posix_getppid();
var_export($p);
echo "\n";
// PPID may be 0 when this binary is PID 1 (docker-exec / container entry).
echo $p >= 0 ? "ok" : "bad", "\n";
echo $p === posix_getppid() ? "stable" : "drift", "\n";
PHP);
        $bin = sys_get_temp_dir().'/phpc_posix_getppid_30728_'.getmypid().'.bin';
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
                $this->assertMatchesRegularExpression(
                    '/^(?:0|[1-9][0-9]*)\nok\nstable\n$/',
                    $text,
                    'run '.($i + 1).': '.$text
                );
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
