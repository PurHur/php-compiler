<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_convert_case() runtime UTF-8 via NestedJIT (#34280 leftover of #11146 / #7014).
 *
 * @see php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_convert_case)
 *
 * @group llvm
 * @group aot
 */
final class MbConvertCaseRuntimeAotTest extends TestCase
{
    public function testAotRuntimeMatchMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = __DIR__.'/../repro/mb_convert_case_runtime_aot.php';
        $this->assertSame($this->runVm($src), $this->runAot($src));
    }

    public function testLoweringDelegatesToJitMbCase(): void
    {
        $root = dirname(__DIR__, 2);
        $jit = (string) file_get_contents($root.'/ext/mbstring/JitMbConvertCase.php');
        $this->assertStringContainsString('JitMbCase::invokeStrtoupper', $jit);
        $this->assertStringContainsString('JitMbCase::invokeStrtolower', $jit);
        // UPPER/LOWER must not use the ASCII-only peel; TITLE still may.
        $this->assertMatchesRegularExpression(
            '/MB_CASE_UPPER[\s\S]*?JitMbCase::invokeStrtoupper/',
            $jit
        );
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/mb_convert_case.c');
    }

    private function runVm(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $cmd = 'env PHP_COMPILER_PROFILE=8.3 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            chdir($cwd);
        }
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/mb_convert_case_'.getmypid().'_'.md5($src);
        $cmd = 'env PHP_COMPILER_PROFILE=8.3 PHP_COMPILER_HELPER_RUNTIME_O=0 '
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
