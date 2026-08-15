<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: lchown/lchgrp/chroot excess argc → ArgumentCountError (#30568).
 *
 * @group llvm
 * @group aot
 */
final class Issue30568LchownChrootExcessArgcAotTest extends TestCase
{
    public function testAotLchownExcessArgcCatchableUnderTry(): void
    {
        $this->assertAotOutput(
            <<<'PHP'
<?php
try {
    lchown('/tmp', 0, 1);
    echo "lchown_hi NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'lchown_hi ', $e->getMessage(), "\n";
}
try {
    lchown('/tmp');
    echo "lchown_lo NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'lchown_lo ', $e->getMessage(), "\n";
}
echo "ok_lchown_argc\n";
PHP,
            "lchown_hi lchown() expects exactly 2 arguments, 3 given\n"
            ."lchown_lo lchown() expects exactly 2 arguments, 1 given\n"
            ."ok_lchown_argc\n"
        );
    }

    public function testAotChrootExcessArgcCatchableUnderTry(): void
    {
        $this->assertAotOutput(
            <<<'PHP'
<?php
try {
    chroot('/tmp', 1);
    echo "chroot_hi NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'chroot_hi ', $e->getMessage(), "\n";
}
try {
    chroot();
    echo "chroot_lo NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'chroot_lo ', $e->getMessage(), "\n";
}
echo "ok_chroot_argc\n";
PHP,
            "chroot_hi chroot() expects exactly 1 argument, 2 given\n"
            ."chroot_lo chroot() expects exactly 1 argument, 0 given\n"
            ."ok_chroot_argc\n"
        );
    }

    private function assertAotOutput(string $srcCode, string $expected): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30568_try_'.getmypid().'_'.mt_rand().'.php';
        $bin = sys_get_temp_dir().'/phpc_30568_try_'.getmypid().'_'.mt_rand().'.bin';
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
