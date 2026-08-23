<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_strtoupper()/mb_strtolower() + mb_convert_case(TITLE) runtime via NestedJIT helpers.
 *
 * @see php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_strtoupper), PHP_FUNCTION(mb_strtolower),
 *      PHP_FUNCTION(mb_convert_case)
 *
 * @group llvm
 * @group aot
 */
final class MbCaseRuntimeAotTest extends TestCase
{
    public function testAotRuntimeMatchMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertAotMatchesZend(__DIR__.'/../repro/mb_case_runtime_aot.php');
    }

    public function testAotTitleRuntimeMatchMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertAotMatchesZend(__DIR__.'/../repro/mb_convert_case_title_runtime_aot.php');
    }

    public function testHelperAndLoweringPresent(): void
    {
        $root = dirname(__DIR__, 2);
        $helper = (string) file_get_contents($root.'/ext/mbstring/MbCaseJitHelper.php');
        $this->assertStringContainsString('function strtoupperArgv', $helper);
        $this->assertStringContainsString('function strtolowerArgv', $helper);
        $runtime = (string) file_get_contents($root.'/lib/JIT/Builtin/MbCaseRuntime.php');
        $this->assertStringContainsString('strtoupperHelper', $runtime);
        $this->assertStringContainsString('strtolowerHelper', $runtime);
        foreach (['mb_strtoupper', 'mb_strtolower'] as $fn) {
            $src = (string) file_get_contents($root.'/ext/mbstring/'.$fn.'.php');
            $this->assertStringContainsString('JitMbCase::invoke', $src);
            $this->assertStringNotContainsString(
                "throw new \\LogicException('".$fn."() is not lowered for JIT/AOT",
                $src
            );
        }
        $titleHelper = (string) file_get_contents($root.'/ext/mbstring/MbConvertCaseJitHelper.php');
        $this->assertStringContainsString('function titleArgv', $titleHelper);
        $this->assertStringContainsString('function titleSimpleArgv', $titleHelper);
        $titleRuntime = (string) file_get_contents($root.'/lib/JIT/Builtin/MbConvertCaseRuntime.php');
        $this->assertStringContainsString('titleHelper', $titleRuntime);
        $convert = (string) file_get_contents($root.'/ext/mbstring/JitMbConvertCase.php');
        $this->assertStringContainsString('MbConvertCaseRuntime::titleHelper', $convert);
        $this->assertStringNotContainsString('asciiTitleRuntime', $convert);
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/mb_strtoupper.c');
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/mb_strtolower.c');
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/mb_convert_case.c');
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
        $bin = sys_get_temp_dir().'/mb_case_'.getmypid().'_'.md5($src);
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
