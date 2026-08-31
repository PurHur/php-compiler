<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT: hash_pbkdf2() rejects non-positive iterations and negative length (#12230, ext/hash/hash_pbkdf2.c).
 *
 * @group llvm
 * @group aot
 *
 * @see php-src ext/hash/hash_pbkdf2.c php_hash_pbkdf2()
 */
final class HashPbkdf2Iterations12230AotTest extends TestCase
{
    public function testAotMatchesZendFixture(): void
    {
        if (!\PHPCompiler\LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/maintainer_gap_hash_pbkdf2_iterations_valueerror.php';
        $this->assertFileExists($src);
        $bin = sys_get_temp_dir().'/phpc_hash_pbkdf2_iter_12230_'.getmypid().'.bin';
        try {
            $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
                .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
            exec($compile, $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, implode("\n", $compileOut));
            $aotOut = [];
            exec(escapeshellarg($bin).' 2>&1', $aotOut, $aotRc);
            $this->assertSame(0, $aotRc, implode("\n", $aotOut));
            $zendOut = [];
            exec(
                escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1',
                $zendOut,
                $zendRc
            );
            $this->assertSame(0, $zendRc, implode("\n", $zendOut));
            $this->assertSame(
                implode("\n", $zendOut)."\n",
                implode("\n", $aotOut)."\n",
                'AOT output must match Zend for hash_pbkdf2 iterations/length ValueError repro'
            );
        } finally {
            @unlink($bin);
        }
    }

    public function testVmMatchesZendFixture(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/maintainer_gap_hash_pbkdf2_iterations_valueerror.php';
        $zendOut = [];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $vmOut = [];
        exec(
            escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php').' '.escapeshellarg($src).' 2>&1',
            $vmOut,
            $vmRc
        );
        $this->assertSame(0, $vmRc, implode("\n", $vmOut));
        $this->assertSame(implode("\n", $zendOut)."\n", implode("\n", $vmOut)."\n");
    }
}
