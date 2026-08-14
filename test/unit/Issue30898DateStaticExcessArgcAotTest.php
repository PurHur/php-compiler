<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: date static excess argc → ArgumentCountError (#30898).
 *
 * php-src: ext/date/php_date.c
 *
 * @group llvm
 * @group aot
 */
final class Issue30898DateStaticExcessArgcAotTest extends TestCase
{
    public function testAotExcessArgcCatchableUnderTry(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30898_try_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30898_try_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
try {
    DateTimeZone::listAbbreviations('x');
    echo "abbr NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'abbr ', $e->getMessage(), "\n";
}
try {
    DateTimeImmutable::createFromFormat('Y-m-d', '2020-01-01', 'UTC', 'extra');
    echo "cff NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'cff ', $e->getMessage(), "\n";
}
$ok = DateTimeZone::listAbbreviations();
$dt = DateTimeImmutable::createFromFormat('Y-m-d', '2020-01-01');
echo (is_array($ok) && $dt instanceof DateTimeImmutable) ? "ok\n" : "bad\n";
PHP);
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
                $this->assertSame(0, $runRc, 'run '.$i.': '.implode("\n", $runOut));
                $this->assertSame(
                    "abbr DateTimeZone::listAbbreviations() expects exactly 0 arguments, 1 given\n"
                    ."cff DateTimeImmutable::createFromFormat() expects at most 3 arguments, 4 given\n"
                    ."ok\n",
                    implode("\n", $runOut)."\n",
                    'run '.$i
                );
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
