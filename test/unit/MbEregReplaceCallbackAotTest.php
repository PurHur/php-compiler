<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_ereg_replace_callback() via ERE→PCRE + preg thin callback (#35335).
 *
 * @see php-src ext/mbstring/php_mbregex.c PHP_FUNCTION(mb_ereg_replace_callback)
 *
 * @group llvm
 * @group aot
 */
final class MbEregReplaceCallbackAotTest extends TestCase
{
    public function testAotNamedCallbackMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertAotMatchesZend(__DIR__.'/../repro/aot_mb_ereg_replace_callback.php');
    }

    public function testLoweringPresent(): void
    {
        $root = dirname(__DIR__, 2);
        $helper = (string) file_get_contents($root.'/ext/mbstring/MbEregJitHelper.php');
        $this->assertStringContainsString('function eregToPcrePatternArgv', $helper);
        $runtime = (string) file_get_contents($root.'/lib/JIT/Builtin/MbEregRuntime.php');
        $this->assertStringContainsString('eregToPcreHelper', $runtime);
        $src = (string) file_get_contents($root.'/ext/mbstring/mb_ereg_replace_callback.php');
        $this->assertStringContainsString('JitMbEreg::invokeReplaceCallback', $src);
        $this->assertStringNotContainsString(
            "throw new \\LogicException('mb_ereg_replace_callback() is not lowered for JIT/AOT",
            $src
        );
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/mb_ereg_replace_callback.c');
    }

    private function assertAotMatchesZend(string $src): void
    {
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
    }

    private function runPhp(string $src): string
    {
        $out = [];
        exec('php '.escapeshellarg($src).' 2>/dev/null', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out)."\n";
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/phpc_mb_ereg_replace_cb_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=1 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $out = [];
            exec(escapeshellarg($bin).' 2>/dev/null', $out, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $out));

            return implode("\n", $out)."\n";
        } finally {
            @unlink($bin);
        }
    }
}
