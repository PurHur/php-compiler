<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_detect_order() runtime string setter (#35280 leftover of #13100/#35278).
 *
 * @see php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_detect_order)
 *
 * @group llvm
 * @group aot
 */
final class MbDetectOrderRuntimeAotTest extends TestCase
{
    public function testAotRuntimeSetterMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertAotMatchesZend(__DIR__.'/../repro/aot_mb_detect_order_runtime.php');
    }

    public function testHelperAndLoweringPresent(): void
    {
        $root = dirname(__DIR__, 2);
        $helper = (string) file_get_contents($root.'/ext/mbstring/MbDetectOrderJitHelper.php');
        $this->assertStringContainsString('function parseOrderArgv', $helper);
        $runtime = (string) file_get_contents($root.'/lib/JIT/Builtin/MbDetectOrderRuntime.php');
        $this->assertStringContainsString('parseHelper', $runtime);
        $this->assertStringContainsString('G_ORDER_CSV', $runtime);
        $this->assertStringContainsString('MbDetectOrderJitHelper::parseOrderArgv', $runtime);
        $this->assertStringContainsString('phpc_mb_detect_order_parse', $runtime);
        $this->assertStringContainsString('ensureBridge', $runtime);
        $this->assertStringContainsString('\\strlen(', $helper);
        $this->assertStringNotContainsString('byteLen', $helper);
        $jit = (string) file_get_contents($root.'/ext/mbstring/JitMbDetectOrder.php');
        $this->assertStringContainsString('MbDetectOrderRuntime', $jit);
        $src = (string) file_get_contents($root.'/ext/mbstring/mb_detect_order.php');
        $this->assertStringContainsString('JitMbDetectOrder::invoke', $src);
        $this->assertStringNotContainsString(
            'JIT setter requires a compile-time string in this compiler build',
            $src
        );
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/mb_detect_order.c');
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/phpc_mb_detect_order.c');
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
        $bin = sys_get_temp_dir().'/mb_detect_order_rt_'.getmypid().'_'.md5($src);
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
            if (is_file($bin)) {
                @unlink($bin);
            }
        }
    }
}
