<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_ereg_replace() leftover of #30311 (#33765) — TypeError/argc gates + literal fold.
 *
 * @see php-src ext/mbstring/php_mbregex.c PHP_FUNCTION(mb_ereg_replace)
 *
 * @group aot-lint
 */
final class MbEregReplaceAotTest extends TestCase
{
    private const EXPECTED_TE = "argc\nnull-type\n";

    public function testVmTypeErrorAndArgc(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_33765_mb_ereg_replace_aot_typeerror.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_33765_mb_ereg_replace_aot_typeerror.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED_TE, $out);
    }

    /**
     * @group llvm
     * @group aot
     */
    public function testAotTypeErrorAndArgcMatchVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33765_mb_ereg_replace_aot_typeerror.php';
        $runtime = new Runtime();
        $code = file_get_contents($src);
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_33765_mb_ereg_replace_aot_typeerror.php'));
        $vmOut = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED_TE, $vmOut);

        $bin = sys_get_temp_dir().'/phpc_mb_ereg_replace_te_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=1 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>/dev/null', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame($vmOut, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }

    /**
     * @group llvm
     * @group aot
     */
    public function testAotLiteralFoldMatchVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33765_mb_ereg_replace_aot_fold.php';
        $runtime = new Runtime();
        $code = file_get_contents($src);
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_33765_mb_ereg_replace_aot_fold.php'));
        $vmOut = (string) ob_get_clean();
        $this->assertSame("xAx\nHello Earth\nabc\n", $vmOut);

        $bin = sys_get_temp_dir().'/phpc_mb_ereg_replace_fold_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=1 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>/dev/null', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame($vmOut, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }

    public function testCallTypeErrorPathNoLongerLogicExceptionOnly(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/ext/mbstring/mb_ereg_replace.php');
        $this->assertStringContainsString('emitArgumentCountErrorAndAbort', $src);
        $this->assertStringContainsString('#33765', $src);
        $this->assertStringContainsString('tryEregReplaceFold', $src);
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/mb_ereg_replace.c');
    }
}
