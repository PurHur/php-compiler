<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: ErrorSilenceJitHelper BSS-zero statics must seed error_reporting before stderr gate (#35563).
 *
 * Regression from #35563 — __compiler_phpc_error_level_enabled always returned 0, silencing
 * openssl_digest softfail E_WARNING and all trigger_error output on thin AOT.
 *
 * @group aot-lint
 */
final class Issue35563ErrorSilenceHelperAotTest extends TestCase
{
    /**
     * @group llvm
     * @group aot
     */
    public function testAotErrorLevelGateSeedsStartupReporting(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_35563_error_silence_helper_aot.php';
        $bin = sys_get_temp_dir().'/phpc_er_silence_35563_'.getmypid().'.bin';
        $outFile = sys_get_temp_dir().'/phpc_er_silence_35563_'.getmypid().'.out';
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            exec(escapeshellarg($bin).' >'.escapeshellarg($outFile).' 2>&1', $runOut, $runRc);
            $stdout = (string) file_get_contents($outFile);
            $this->assertSame(0, $runRc, $stdout);
            $this->assertStringStartsWith("true\n", $stdout);
            $this->assertMatchesRegularExpression('/\n22527\n$/', $stdout);
        } finally {
            @unlink($bin);
            @unlink($outFile);
        }
    }

    /**
     * @group llvm
     * @group aot
     */
    public function testAotTriggerErrorPrintsUnderDefaultReporting(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_35563_trigger_warning_aot.php';
        $bin = sys_get_temp_dir().'/phpc_trigger_35563_'.getmypid().'.bin';
        $outFile = sys_get_temp_dir().'/phpc_trigger_35563_'.getmypid().'.out';
        $errFile = sys_get_temp_dir().'/phpc_trigger_35563_'.getmypid().'.err';
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        try {
            exec(
                escapeshellarg($bin)
                    .' >'.escapeshellarg($outFile)
                    .' 2>'.escapeshellarg($errFile),
                $ignored,
                $runRc
            );
            $stderr = (string) file_get_contents($errFile);
            $this->assertSame(0, $runRc, $stderr);
            $this->assertStringContainsString('PHP Warning:  hello warning', $stderr);
        } finally {
            @unlink($bin);
            @unlink($outFile);
            @unlink($errFile);
        }
    }

    public function testEnsureCompiledModuleDefaultsWired(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Builtin/SilenceRuntime.php');
        $this->assertStringContainsString('G_ERROR_REPORTING', $src);
        $this->assertStringContainsString('#35563', $src);
        $this->assertStringContainsString('DEFAULT_STARTUP_REPORTING', $src);
    }
}
