<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT execute guard for version_compare() 3-arg operator (#26866).
 *
 * php-src: ext/standard/versioning.c — PHP_FUNCTION(version_compare)
 *
 * @group llvm
 * @group aot
 */
final class VersionCompareAot26866Test extends TestCase
{
    public function testAotVersionCompareOperatorExecute(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_version_compare_26866_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
echo version_compare("8.2.0", "8.3.0", "<") ? "lt" : "ge", "\n";
echo version_compare("1.0.0", "1.0.0", "=") ? "eq" : "ne", "\n";
var_export(version_compare("8.2.0", "8.3.0"));
echo "\n";
PHP);
        $bin = sys_get_temp_dir().'/phpc_version_compare_26866_'.getmypid().'.bin';
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
                $this->assertSame("lt\neq\n-1\n", implode("\n", $runOut)."\n");
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
