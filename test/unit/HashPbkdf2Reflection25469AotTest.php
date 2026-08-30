<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT: hash_pbkdf2 Reflection types / optionality / return (leftover #25469, ext/hash/hash.stub.php).
 *
 * @group llvm
 * @group aot
 *
 * @see php-src ext/hash/hash.stub.php hash_pbkdf2
 */
final class HashPbkdf2Reflection25469AotTest extends TestCase
{
    public function testReflectionMetadataMatchesZend(): void
    {
        if (!\PHPCompiler\LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/maintainer_gap_hash_pbkdf2_reflection.php';
        $this->assertFileExists($src);
        $bin = sys_get_temp_dir().'/phpc_hash_pbkdf2_refl_25469_'.getmypid().'.bin';
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
                'AOT output must match Zend for hash_pbkdf2 reflection repro'
            );
        } finally {
            @unlink($bin);
        }
    }
}
