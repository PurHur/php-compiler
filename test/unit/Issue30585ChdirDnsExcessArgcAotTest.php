<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: chdir/gethostbyname/gethostbynamel excess argc → ArgumentCountError (#30585).
 *
 * php-src: ext/standard/dir.c / dns.c
 *
 * Separate binaries per helper family so ACE + NestedJIT link do not orphan the insert block.
 *
 * @group llvm
 * @group aot
 */
final class Issue30585ChdirDnsExcessArgcAotTest extends TestCase
{
    public function testAotChdirExcessArgcCatchableUnderTry(): void
    {
        $this->assertAotOutput(
            <<<'PHP'
<?php
try {
    chdir('.', 'extra');
    echo "chdir_hi NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'chdir_hi ', $e->getMessage(), "\n";
}
try {
    chdir();
    echo "chdir_lo NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'chdir_lo ', $e->getMessage(), "\n";
}
echo "ok_chdir_argc\n";
PHP,
            "chdir_hi chdir() expects exactly 1 argument, 2 given\n"
            ."chdir_lo chdir() expects exactly 1 argument, 0 given\n"
            ."ok_chdir_argc\n"
        );
    }

    public function testAotGethostbynameExcessArgcCatchableUnderTry(): void
    {
        $this->assertAotOutput(
            <<<'PHP'
<?php
try {
    gethostbyname('localhost', 'extra');
    echo "name_hi NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'name_hi ', $e->getMessage(), "\n";
}
try {
    gethostbyname();
    echo "name_lo NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'name_lo ', $e->getMessage(), "\n";
}
echo "ok_name_argc\n";
PHP,
            "name_hi gethostbyname() expects exactly 1 argument, 2 given\n"
            ."name_lo gethostbyname() expects exactly 1 argument, 0 given\n"
            ."ok_name_argc\n"
        );
    }

    public function testAotGethostbynamelExcessArgcCatchableUnderTry(): void
    {
        $this->assertAotOutput(
            <<<'PHP'
<?php
try {
    gethostbynamel('localhost', 'extra');
    echo "list_hi NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'list_hi ', $e->getMessage(), "\n";
}
try {
    gethostbynamel();
    echo "list_lo NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'list_lo ', $e->getMessage(), "\n";
}
echo "ok_list_argc\n";
PHP,
            "list_hi gethostbynamel() expects exactly 1 argument, 2 given\n"
            ."list_lo gethostbynamel() expects exactly 1 argument, 0 given\n"
            ."ok_list_argc\n"
        );
    }

    private function assertAotOutput(string $srcCode, string $expected): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30585_try_'.getmypid().'_'.mt_rand().'.php';
        $bin = sys_get_temp_dir().'/phpc_30585_try_'.getmypid().'_'.mt_rand().'.bin';
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
