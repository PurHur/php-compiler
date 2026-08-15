<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: getservbyname/getservbyport excess argc → ArgumentCountError (#30567).
 *
 * php-src: ext/standard/network.c
 *
 * @group llvm
 * @group aot
 */
final class Issue30567GetservExcessArgcAotTest extends TestCase
{
    public function testAotGetservbynameExcessArgcCatchableUnderTry(): void
    {
        $this->assertAotOutput(
            <<<'PHP'
<?php
try {
    getservbyname('http', 'tcp', 1);
    echo "name_hi NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'name_hi ', $e->getMessage(), "\n";
}
try {
    getservbyname('http');
    echo "name_lo NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'name_lo ', $e->getMessage(), "\n";
}
echo "ok_name_argc\n";
PHP,
            "name_hi getservbyname() expects exactly 2 arguments, 3 given\n"
            ."name_lo getservbyname() expects exactly 2 arguments, 1 given\n"
            ."ok_name_argc\n"
        );
    }

    public function testAotGetservbyportExcessArgcCatchableUnderTry(): void
    {
        $this->assertAotOutput(
            <<<'PHP'
<?php
try {
    getservbyport(80, 'tcp', 1);
    echo "port_hi NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'port_hi ', $e->getMessage(), "\n";
}
try {
    getservbyport(80);
    echo "port_lo NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'port_lo ', $e->getMessage(), "\n";
}
echo "ok_port_argc\n";
PHP,
            "port_hi getservbyport() expects exactly 2 arguments, 3 given\n"
            ."port_lo getservbyport() expects exactly 2 arguments, 1 given\n"
            ."ok_port_argc\n"
        );
    }

    private function assertAotOutput(string $srcCode, string $expected): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30567_try_'.getmypid().'_'.mt_rand().'.php';
        $bin = sys_get_temp_dir().'/phpc_30567_try_'.getmypid().'_'.mt_rand().'.bin';
        file_put_contents($src, $srcCode);
        $compile = escapeshellarg(PHP_BINARY).' '
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
                $this->assertSame($expected, implode("\n", $runOut)."\n", 'run '.$i);
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
