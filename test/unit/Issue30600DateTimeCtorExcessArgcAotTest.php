<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: DateTime/DateTimeImmutable::__construct excess argc → ArgumentCountError (#30600).
 *
 * php-src: ext/date/php_date.c
 *
 * @group llvm
 * @group aot
 */
final class Issue30600DateTimeCtorExcessArgcAotTest extends TestCase
{
    public function testAotValidArityStillWorks(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30600_ok_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30600_ok_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
$o = new DateTime('now');
echo $o->format('Y') !== '' ? 'ok' : 'fail', "\n";
$tz = new DateTimeZone('UTC');
$i = new DateTimeImmutable('2020-01-01', $tz);
echo $i->format('Y-m-d'), "\n";
PHP);
        $this->compileAndAssertOutput($root, $src, $bin, "ok\n2020-01-01\n");
    }

    public function testAotExcessArgcCatchableUnderTry(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30600_try_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30600_try_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
try {
    $o = new DateTime('now', null, 1);
    echo "NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
try {
    $o = new DateTimeImmutable('now', null, 1);
    echo "NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
PHP);
        $this->compileAndAssertOutput(
            $root,
            $src,
            $bin,
            "DateTime::__construct() expects at most 2 arguments, 3 given\n"
            ."DateTimeImmutable::__construct() expects at most 2 arguments, 3 given\n"
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
