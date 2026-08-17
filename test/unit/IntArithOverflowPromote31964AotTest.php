<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: integer + / * overflow promotes to float (#31964).
 *
 * @see php-src Zend/zend_operators.h ZEND_SIGNED_ADD_OVERFLOW / ZEND_LONG_MUL_OVERFLOW
 *
 * @group llvm
 * @group aot
 */
final class IntArithOverflowPromote31964AotTest extends TestCase
{
    public function testAotIntOverflowPromotesToFloat(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_31964_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_31964_'.getmypid().'.bin';
        file_put_contents($src, file_get_contents($root.'/test/repro/maintainer_gap_aot_int_overflow.php'));
        $this->compileAndRun($root, $src, $bin);
    }

    private function compileAndRun(string $root, string $src, string $bin): void
    {
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, 'compile: '.implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $joined = implode("\n", $runOut);
            $this->assertStringContainsString('float(9.223372036854', $joined);
            $this->assertStringContainsString('float(1.84467440737', $joined);
            $this->assertStringNotContainsString('int(-9223372036854775808)', $joined);
            $this->assertStringNotContainsString('int(2)', $joined);
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
