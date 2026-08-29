<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_substr/mb_strcut negative offset + null length (php-src mbstring.c; #21430).
 *
 * @see php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_substr), PHP_FUNCTION(mb_strcut)
 *
 * @group llvm
 * @group aot
 */
final class MbNegativeOffsetNullLengthAotTest extends TestCase
{
    public function testStrictAotMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = __DIR__.'/../repro/mb_negative_offset_null_length_aot.php';
        $this->assertSame($this->runPhp($src), $this->runAot($src));
    }

    public function testNoStrictAotMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = __DIR__.'/../repro/maintainer_gap_mb_negative_offset_null_length_no_strict.php';
        $this->assertSame($this->runPhp($src), $this->runAot($src));
    }

    public function testJitMbStrcutNullLengthUsesSentinel(): void
    {
        $jit = (string) file_get_contents(dirname(__DIR__, 2).'/ext/mbstring/JitMbStrcut.php');
        $this->assertStringContainsString('isNullConstant ?? false', $jit);
    }

    private function runPhp(string $src): string
    {
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/mb_neg_null_'.getmypid();
        @unlink($bin);
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $cout, $crc);
        $this->assertSame(0, $crc, implode("\n", $cout));
        $this->assertFileExists($bin);
        exec(escapeshellarg($bin).' 2>&1', $out, $rc);
        @unlink($bin);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }
}
