<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: idate() excess argc → ArgumentCountError (#30543).
 *
 * php-src: ext/date/php_date.c
 *
 * @group llvm
 * @group aot
 */
final class Issue30543IdateExcessArgcAotTest extends TestCase
{
    public function testAotValidArityStillWorks(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30543_ok_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30543_ok_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
echo idate('Y', strtotime('2020-06-15 12:00:00')), "\n";
PHP);
        $this->compileAndAssertOutput($root, $src, $bin, "2020\n");
    }

    public function testAotExcessArgcCatchableUnderTry(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30543_try_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30543_try_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
try {
    idate('Y', time(), 1);
    echo "NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
PHP);
        $this->compileAndAssertOutput(
            $root,
            $src,
            $bin,
            "idate() expects at most 2 arguments, 3 given\n"
        );
    }

    private function compileAndAssertOutput(string $root, string $src, string $bin, string $expected): void
    {
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
                $this->assertSame($expected, implode("\n", $runOut)."\n", 'run '.($i + 1));
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
