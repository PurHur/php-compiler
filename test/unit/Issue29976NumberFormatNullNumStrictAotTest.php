<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: number_format(null) TypeError cites int|float under strict_types (#29976).
 *
 * php-src: ext/standard/basic_functions.stub.php — int|float $num
 *
 * @group llvm
 * @group aot
 */
final class Issue29976NumberFormatNullNumStrictAotTest extends TestCase
{
    public function testAotNullNumStrictTypeErrorCitesIntFloat(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_29976_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_29976_'.getmypid().'.bin';
        // Match working AOT repro shape (Throwable catch, no trailing try-body stmts).
        file_put_contents($src, <<<'PHP'
<?php
declare(strict_types=1);
try {
    number_format(null);
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
PHP);
        $compile = 'PHP_COMPILER_PROFILE=8.4 '
            .escapeshellarg(PHP_BINARY).' '
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
                $joined = implode("\n", $runOut)."\n";
                $this->assertSame(
                    "number_format(): Argument #1 (\$num) must be of type int|float, null given\n",
                    $joined
                );
                $this->assertStringNotContainsString('must be of type float,', $joined);
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
