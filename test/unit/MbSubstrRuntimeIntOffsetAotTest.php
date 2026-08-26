<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_substr/mb_strcut runtime int offsets must not NestedJIT-zero (#34881 re-#34256).
 *
 * @group llvm
 * @group aot
 */
final class MbSubstrRuntimeIntOffsetAotTest extends TestCase
{
    public function testAotRuntimeIntMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = __DIR__.'/../repro/mb_substr_runtime_int_aot.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
    }

    public function testHelperNeverReassignsStartFromParams(): void
    {
        $helper = (string) file_get_contents(dirname(__DIR__, 2).'/ext/mbstring/MbStrcutJitHelper.php');
        $this->assertStringContainsString('Never reassign', $helper);
        $this->assertStringContainsString('$startAt = $start', $helper);
        $this->assertStringNotContainsString('$start = $charLen + $start', $helper);
        $this->assertStringNotContainsString('$from = \\strlen', $helper);
        $this->assertStringNotContainsString('private static function', $helper);
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
        $bin = sys_get_temp_dir().'/mb_substr_int_'.getmypid().'_'.md5($src);
        $cmd = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $compOut, $compRc);
            $this->assertSame(0, $compRc, implode("\n", $compOut));
            exec(escapeshellarg($bin).' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            chdir($cwd);
            @unlink($bin);
        }
    }
}
