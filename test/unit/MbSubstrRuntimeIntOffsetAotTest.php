<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_substr()/mb_strcut() runtime int offsets must match Zend (#34881 leftover of #34846 / #34256).
 *
 * NestedJIT zeros rewritten $start/$from params — helpers use $startAt/$fromAt/$lenAt locals.
 *
 * @see php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_substr), PHP_FUNCTION(mb_strcut)
 *
 * @group llvm
 * @group aot
 */
final class MbSubstrRuntimeIntOffsetAotTest extends TestCase
{
    public function testAotRuntimeIntOffsetMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertAotMatchesZend(__DIR__.'/../repro/mb_substr_runtime_int_aot.php');
    }

    public function testHelperNeverReassignsOffsetParams(): void
    {
        $root = dirname(__DIR__, 2);
        $helper = (string) file_get_contents($root.'/ext/mbstring/MbStrcutJitHelper.php');
        $this->assertStringContainsString('$startAt = $start + 0', $helper);
        $this->assertStringContainsString('$fromAt = $from + 0', $helper);
        $this->assertStringContainsString('$lenAt = $length + 0', $helper);
        $this->assertStringContainsString('$endAt = $startAt + $lenAt', $helper);
        $this->assertStringContainsString('if ($charIndex == $startAt)', $helper);
        $this->assertStringNotContainsString('$start = $charLen', $helper);
        $this->assertStringNotContainsString('$from = \\strlen', $helper);
        $this->assertStringNotContainsString('$length = $charLen', $helper);
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/mb_substr.c');
    }

    private function assertAotMatchesZend(string $src): void
    {
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
    }

    private function runPhp(string $src): string
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/mb_substr_rt_'.getmypid().'_'.md5($src);
        $cmd = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $compOut, $compRc);
            $this->assertSame(0, $compRc, implode("\n", $compOut));
            $this->assertFileExists($bin);
            exec(escapeshellarg($bin).' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            chdir($cwd);
            @unlink($bin);
        }
    }
}
