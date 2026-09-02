<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: {main} `$b = $runtime . "lit"` must echo / var_export the concat (#36366).
 *
 * php-src: Zend/zend_operators.c zend_concat — CV holds the result zval.
 *
 * @group llvm
 * @group aot
 */
final class MainScriptConcatEcho36366AotTest extends TestCase
{
    public function testMainScriptRuntimeStringConcatEchoMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($root)) {
            $this->markTestSkipped(LlvmToolchain::readyFailureReason() ?? 'LLVM 9 required');
        }
        $src = $root.'/test/repro/issue_36366_main_concat_echo.php';
        $this->assertFileExists($src);

        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $zend = implode("\n", $zendOut);

        $outBin = sys_get_temp_dir().'/phpc_36366_'.getmypid().'_'.mt_rand().'.bin';
        $cmd = escapeshellarg(PHP_BINARY).' -d memory_limit=2048M '
            .escapeshellarg($root.'/bin/compile.php').' -o '
            .escapeshellarg($outBin).' '
            .escapeshellarg($src).' 2>&1';
        exec($cmd, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($outBin);
        try {
            exec(escapeshellarg($outBin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $aot = implode("\n", $runOut);
            $this->assertSame($zend, $aot);
            $this->assertStringContainsString('echo=[ZY]', $aot);
            $this->assertStringContainsString('sprintf_concat=[hi!] len=3', $aot);
        } finally {
            @unlink($outBin);
        }
    }
}
