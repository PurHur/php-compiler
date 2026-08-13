<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT execute guard for posix_geteuid() (#30767).
 *
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_geteuid) / geteuid(2)
 *
 * @group llvm
 * @group aot
 */
final class PosixGeteuidAot30767Test extends TestCase
{
    public function testAotPosixGeteuidMatchesHost(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $expect = (int) \posix_geteuid();
        $src = sys_get_temp_dir().'/phpc_posix_geteuid_30767_'.getmypid().'.php';
        file_put_contents($src, <<<PHP
<?php
\$u = posix_geteuid();
var_export(\$u);
echo "\\n";
echo \$u >= 0 ? "ok" : "bad", "\\n";
echo \$u === {$expect} ? "match" : "mismatch", "\\n";
PHP);
        $bin = sys_get_temp_dir().'/phpc_posix_geteuid_30767_'.getmypid().'.bin';
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
                    '/^(?:0|[1-9][0-9]*)\nok\nmatch\n$/',
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
