<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: PDO::getAvailableDrivers / pdo_drivers excess argc → ArgumentCountError (#30994).
 *
 * php-src: ext/pdo/pdo.c
 *
 * @group llvm
 * @group aot
 */
final class Issue30994PdoGetAvailableDriversExcessArgcAotTest extends TestCase
{
    public function testAotExcessArgcCatchableUnderTry(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30994_try_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30994_try_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
try {
    PDO::getAvailableDrivers(1);
    echo "method NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'method ', $e->getMessage(), "\n";
}
try {
    pdo_drivers(1);
    echo "proc NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'proc ', $e->getMessage(), "\n";
}
$ok = PDO::getAvailableDrivers();
$ok2 = pdo_drivers();
echo (is_array($ok) && is_array($ok2)) ? "ok\n" : "bad\n";
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
                    "method PDO::getAvailableDrivers() expects exactly 0 arguments, 1 given\n"
                    ."proc pdo_drivers() expects exactly 0 arguments, 1 given\n"
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
