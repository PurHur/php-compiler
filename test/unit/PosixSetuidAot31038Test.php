<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT execute guard for posix_setuid() (#31038).
 *
 * Restores current uid (no privilege change) — php-src posix_setuid(getuid()) succeeds.
 * Avoid `$x === 0` / `$ok === true` — identical-to-zero/true on locals from i1/int64
 * bridges trips "Current basic block has no parent function" under AOT as root
 * (same class as PosixGetuidAot30744 when uid===0).
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_setuid) / setuid(2)
 *
 * @group llvm
 * @group aot
 */
final class PosixSetuidAot31038Test extends TestCase
{
    public function testAotPosixSetuidCurrentUidMatchesHost(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        if (!\function_exists('posix_setuid') || !\function_exists('posix_getuid')) {
            $this->markTestSkipped('host posix_setuid unavailable');
        }
        $root = dirname(__DIR__, 2);
        $uid = (int) \posix_getuid();
        $src = sys_get_temp_dir().'/phpc_posix_setuid_31038_'.getmypid().'.php';
        file_put_contents($src, <<<PHP
<?php
\$u = posix_getuid();
\$ok = posix_setuid(\$u);
var_export(\$ok);
echo "\\n";
echo \$ok ? "ok" : "bad", "\\n";
var_export(\$u);
echo "\\n";
PHP);
        $bin = sys_get_temp_dir().'/phpc_posix_setuid_31038_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        $expect = 'true'."\n".'ok'."\n".var_export($uid, true)."\n";
        try {
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $text = implode("\n", $runOut)."\n";
                $this->assertSame($expect, $text, 'run '.($i + 1));
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
