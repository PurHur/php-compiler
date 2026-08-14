<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT execute guard for posix_getegid() (#30986).
 *
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_getegid) / getegid(2)
 *
 * Single-echo AOT (no post-call compare): helper-runtime cache hit + `$g >= 0`
 * currently detaches the user-script insert block (same class as PosixGetgidAot30803Test).
 *
 * @group llvm
 * @group aot
 */
final class PosixGetegidAot30986Test extends TestCase
{
    public function testAotPosixGetegidMatchesHost(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $expect = (int) \posix_getegid();
        $src = sys_get_temp_dir().'/phpc_posix_getegid_30986_'.getmypid().'.php';
        file_put_contents($src, "<?php echo posix_getegid(), PHP_EOL;\n");
        $bin = sys_get_temp_dir().'/phpc_posix_getegid_30986_'.getmypid().'.bin';
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
                $this->assertSame([(string) $expect], $runOut, 'run '.($i + 1));
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
