<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_ereg_replace_callback() via ERE→PCRE + preg callback bridge (#35335).
 *
 * @see php-src ext/mbstring/php_mbregex.c PHP_FUNCTION(mb_ereg_replace_callback)
 *
 * @group aot-lint
 */
final class MbEregReplaceCallback35335AotTest extends TestCase
{
    /**
     * @group llvm
     * @group aot
     */
    public function testAotNamedCallbackMatchVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_35335_mb_ereg_replace_callback_aot.php';
        $runtime = new Runtime();
        $code = file_get_contents($src);
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_35335_mb_ereg_replace_callback_aot.php'));
        $vmOut = (string) ob_get_clean();
        $this->assertSame("xAAAy\nxAAAy\n", $vmOut);

        $bin = sys_get_temp_dir().'/phpc_mb_ereg_replace_cb_35335_'.getmypid().'.bin';
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

    public function testLoweringUsesPregBridgeNotLogicExceptionStub(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/ext/mbstring/mb_ereg_replace_callback.php');
        $this->assertStringContainsString('JitMbEregReplaceCallback::invoke', $src);
        $this->assertStringNotContainsString(
            'is not lowered for JIT/AOT in this compiler build',
            $src
        );
        $this->assertFileExists(dirname(__DIR__, 2).'/ext/mbstring/JitMbEregReplaceCallback.php');
    }
}
