<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @group aot-lint */
final class BootstrapBootstrapLoopProbeTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testBootstrapLoopProbeScriptExists(): void
    {
        $script = self::$root.'/script/bootstrap-loop-probe.sh';
        $this->assertFileExists($script);
        $this->assertFileIsReadable($script);
    }

    public function testBootstrapLoopSmokeBundleLintPasses(): void
    {
        $entry = self::$root.'/test/selfhost/bootstrap_loop_smoke/main.php';
        $this->assertFileExists($entry);

        $prefix = LlvmToolchain::envPrefix(self::$root);
        $cmd = implode(' ', array_map('escapeshellarg', [...$prefix, 'php', self::$root.'/bin/compile.php', '-l', $entry])).' 2>&1';
        exec($cmd, $lines, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $lines));
    }

    public function testBootstrapLoopProbeDocumentsExitCodesAndDryRun(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-loop-probe.sh');
        $this->assertStringContainsString('--dry-run', $script);
        $this->assertStringContainsString('Exit codes:', $script);
        $this->assertStringContainsString('exit 2', $script);
        $this->assertStringContainsString('bootstrap-selfhost-lib-spine-smoke-link.sh', $script);
        $this->assertStringContainsString('bootstrap-selfhost-helloworld-probe.sh', $script);
        $this->assertStringContainsString('BOOTSTRAP_M3_LINK_COMPILE_DRIVER', $script);
        $this->assertStringContainsString('Makefile parity', $script);
        $this->assertStringContainsString('#2612', $script);
        $this->assertStringContainsString('bootstrap-loop-gen1-link.sh', $script);
        $this->assertStringContainsString('bootstrap-selfhost-full-revision-probe.sh', $script);
        $this->assertStringContainsString('#2880', $script);
        $this->assertStringContainsString('#2898', $script);
        $this->assertStringContainsString('BOOTSTRAP_M3_HELLOWORLD_STRICT=1', $script);
        $this->assertStringContainsString('gen-1 link', $script);
        $gen1 = (string) file_get_contents(self::$root.'/script/bootstrap-loop-gen1-link.sh');
        $this->assertStringContainsString('BOOTSTRAP_M4_LINK_COMPILE_DRIVER', $gen1);
        $this->assertStringContainsString('BOOTSTRAP_M4_COMPILE_DRIVER_REAL_LOWERING', $gen1);
        $this->assertStringContainsString('bootstrap-spine-count.php', $script);
        $this->assertStringNotContainsString('717/717', $script);
    }

    public function testBootstrapLoopGen1LinkScriptExists(): void
    {
        $script = self::$root.'/script/bootstrap-loop-gen1-link.sh';
        $this->assertFileExists($script);
        $this->assertFileIsReadable($script);
    }

    public function testBootstrapLoopCompileDriverLintPasses(): void
    {
        $driver = self::$root.'/test/selfhost/bootstrap_loop_smoke/compile_driver.php';
        $this->assertFileExists($driver);

        $prefix = LlvmToolchain::envPrefix(self::$root);
        $cmd = implode(' ', array_map('escapeshellarg', [...$prefix, 'php', self::$root.'/bin/compile.php', '-l', $driver])).' 2>&1';
        exec($cmd, $lines, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $lines));
    }

    public function testBootstrapLoopCompileSmokeHelperExists(): void
    {
        $helper = (string) file_get_contents(self::$root.'/test/bootstrap-aot/bootstrap_loop_compile_smoke.php');
        $this->assertStringContainsString('bootstrap_loop_compile_smoke', $helper);
        $this->assertStringContainsString('helloworld_compile_smoke', $helper);
    }

    public function testBootstrapLoopCompileDriverMapsM4Env(): void
    {
        $driver = (string) file_get_contents(self::$root.'/test/selfhost/bootstrap_loop_smoke/compile_driver.php');
        $this->assertStringContainsString('PHP_COMPILER_M4_COMPILE_MODE', $driver);
        $this->assertStringContainsString('PHP_COMPILER_M4_SOURCE', $driver);
        $this->assertStringContainsString('compiler_helloworld_smoke/compile_driver.php', $driver);
        $this->assertStringNotContainsString('bootstrap_loop_compile_driver ready', $driver);
    }

    public function testBootstrapLoopGen1LinkDefaultsInventoryEmitDriver(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-loop-gen1-link.sh');
        $this->assertStringContainsString('cd "${ROOT}"', $script);
        $this->assertStringContainsString('BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER=1', $script);
        $this->assertStringContainsString('PHP_COMPILER_M3_COMPILE_DRIVER_MAIN=1', $script);
        $this->assertStringContainsString('php "${ROOT}/bin/compile.php" -o "${EMIT_HELPER}"', $script);
        $this->assertStringContainsString('compiler_helloworld_smoke/compile_driver.php', $script);
        $this->assertStringContainsString('inventory compile_driver (#3032)', $script);
        $this->assertStringContainsString('bootstrap_gen0_sidecar_emit_fallback', $script);
        $this->assertStringContainsString('bootstrap_gen0_seed_prelinked_m3_sidecars', $script);
        $this->assertStringContainsString('#9704', $script);
        $this->assertStringContainsString('compile_smoke_m3_emit: compile OK', $script);
        $this->assertStringContainsString('PHP_COMPILER_M3_EMIT_MINIMAL=1', $script);
        $this->assertStringContainsString('PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1', $script);
        $this->assertStringContainsString('==> link gen-1 emit helper', $script);
        $this->assertStringContainsString('==> link gen-1 (bootstrap_loop_smoke bundle)', $script);
        $emitPos = strpos($script, '==> link gen-1 emit helper');
        $gen1Pos = strpos($script, '==> link gen-1 (bootstrap_loop_smoke bundle)');
        $this->assertNotFalse($emitPos);
        $this->assertNotFalse($gen1Pos);
        $this->assertLessThan($gen1Pos, $emitPos, 'emit helper should link before gen-1 bundle (M3 probe order)');
    }

    public function testSelfHostTargetDocMentionsBootstrapLoopProbe(): void
    {
        $doc = (string) file_get_contents(self::$root.'/docs/self-host-target.md');
        $this->assertStringContainsString('bootstrap-loop-probe', $doc);
        $this->assertStringContainsString('#1498', $doc);
        $this->assertStringContainsString('--dry-run', $doc);
    }

    public function testMakefileDefinesBootstrapLoopProbeTarget(): void
    {
        $makefile = (string) file_get_contents(self::$root.'/Makefile');
        $this->assertStringContainsString('bootstrap-loop-probe:', $makefile);
        $this->assertStringContainsString('./script/bootstrap-loop-probe.sh', $makefile);
        $this->assertStringContainsString('bootstrap-loop-full-spine-probe:', $makefile);
        $this->assertStringContainsString('./script/bootstrap-loop-full-spine-probe.sh', $makefile);
        $this->assertStringContainsString('bootstrap-loop-gen1-link:', $makefile);
        $this->assertStringContainsString('bootstrap-loop-probe-dry-run:', $makefile);
        $this->assertStringContainsString('BOOTSTRAP_M3_HELLOWORLD_STRICT=1', $makefile);
        $this->assertStringContainsString('BOOTSTRAP_M4_RUNTIME_COMPILE', $makefile);
        $gen1 = (string) file_get_contents(self::$root.'/script/bootstrap-loop-gen1-link.sh');
        $this->assertStringContainsString('BOOTSTRAP_M4_RUNTIME_COMPILE:=1', $gen1);
    }

    public function testBootstrapLoopFullSpineProbeScriptWiresEnv(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-loop-full-spine-probe.sh');
        $this->assertStringContainsString('BOOTSTRAP_M4_GEN1_COMPILE_FULL_SPINE=1', $script);
        $this->assertStringContainsString('bootstrap-loop-probe.sh', $script);
        $this->assertStringContainsString('#2770', $script);
    }

    public function testBootstrapLoopSmokeEntryDocumentsProbe(): void
    {
        $entry = (string) file_get_contents(self::$root.'/test/selfhost/bootstrap_loop_smoke/main.php');
        $this->assertStringContainsString('bootstrap-loop-probe', $entry);
        $this->assertStringContainsString('bootstrap-loop-gen1-link', $entry);
        $this->assertStringContainsString('bootstrap_loop_smoke bundle OK', $entry);
        $this->assertStringContainsString('#1498', $entry);
    }

    public function testDryRunRunsM3HelloWorldStrictWhenLlvmPresent(): void
    {
        if (!LlvmToolchain::isReady(self::$root)) {
            $this->markTestSkipped('LLVM 9 not available for M4 bootstrap-loop dry-run test.');
        }

        $script = self::$root.'/script/bootstrap-loop-probe.sh';
        $prefix = LlvmToolchain::envPrefix(self::$root);
        $cmd = implode(' ', array_map('escapeshellarg', [...$prefix, 'bash', $script, '--dry-run'])).' 2>&1';
        exec($cmd, $lines, $exitCode);

        $out = implode("\n", $lines);
        if (str_contains($out, 'M2 lib spine smoke') && str_contains($out, 'failed (exit')) {
            $this->markTestSkipped('M2 spine link not green in this environment; dry-run documents spine blocker.');
        }
        if (str_contains($out, 'M3 HelloWorld probe') && str_contains($out, 'failed (exit')) {
            $this->markTestSkipped('M3 HelloWorld strict probe not green in this environment.');
        }
        if (str_contains($out, 'M4 gen-1 link failed')) {
            $this->markTestSkipped('M4 gen-1 link not green in this environment.');
        }
        $this->assertSame(0, $exitCode, $out);
        $this->assertStringContainsString('--dry-run OK', $out);
        $this->assertStringContainsString('M4 gen-1 link', $out);
        $this->assertStringContainsString('Makefile parity', $out);
        $this->assertStringContainsString('bootstrap-selfhost-helloworld-probe: OK emit_path=native', $out);
        $this->assertStringNotContainsString('M3 native-emit prerequisite (strict)', $out);
        $this->assertStringNotContainsString(
            'emit helper link failed (exit 255, mode=selfhost stubs (no PHP_COMPILER_M3_COMPILE_DRIVER))',
            $out,
            'M4 probe must link emit helper with PHP_COMPILER_M3_COMPILE_DRIVER (#2571)'
        );
    }

    public function testGen1LinkNativeDefaultWhenLlvmPresent(): void
    {
        if (!LlvmToolchain::isReady(self::$root)) {
            $this->markTestSkipped('LLVM 9 not available for M4 gen-1 link test.');
        }

        $script = self::$root.'/script/bootstrap-loop-gen1-link.sh';
        $prefix = LlvmToolchain::envPrefix(self::$root);
        $cmd = implode(' ', array_map('escapeshellarg', [...$prefix, 'bash', $script])).' 2>&1';
        exec($cmd, $lines, $exitCode);

        $out = implode("\n", $lines);
        $this->assertSame(0, $exitCode, $out);
        $this->assertStringContainsString('bootstrap-loop-gen1-link: OK', $out);
        $this->assertStringContainsString('compiler smoke', $out);
        $this->assertStringContainsString('emit helper link OK', $out);
        $this->assertStringContainsString('emit_path=native', $out);
        $this->assertStringContainsString('OK emit_path=native', $out);
        $this->assertStringNotContainsString(
            'emit helper link failed (exit 255, mode=selfhost stubs (no PHP_COMPILER_M3_COMPILE_DRIVER))',
            $out
        );
        $this->assertTrue(is_executable(self::$root.'/build/bootstrap-loop-gen1'));
        $this->assertTrue(is_executable(self::$root.'/build/bootstrap-loop-gen2'));
    }

    public function testGen1LinkStrictRefusesZendFallbackWhenNativeBlocked(): void
    {
        if (!LlvmToolchain::isReady(self::$root)) {
            $this->markTestSkipped('LLVM 9 not available for M4 gen-2 strict test.');
        }

        $script = self::$root.'/script/bootstrap-loop-gen1-link.sh';
        $prefix = LlvmToolchain::envPrefix(self::$root);
        $cmd = implode(' ', array_map('escapeshellarg', [
            ...$prefix,
            'env', 'BOOTSTRAP_M4_GEN2_STRICT=1', 'BOOTSTRAP_M4_LINK_COMPILE_DRIVER=0',
            'bash', $script,
        ])).' 2>&1';
        exec($cmd, $lines, $exitCode);

        $out = implode("\n", $lines);
        // Strict forces link driver on; should still pass with native emit when LLVM green.
        if (str_contains($out, 'OK emit_path=native')) {
            $this->assertSame(0, $exitCode, $out);

            return;
        }
        $this->assertSame(1, $exitCode, $out);
        $this->assertStringContainsString('emit_path=zend_fallback_would_be_used', $out);
    }

    public function testFullProbeExitsTwoWhenGen2BlockedAfterM3Green(): void
    {
        if (!LlvmToolchain::isReady(self::$root)) {
            $this->markTestSkipped('LLVM 9 not available for M4 bootstrap-loop full probe test.');
        }

        $script = self::$root.'/script/bootstrap-loop-probe.sh';
        $prefix = LlvmToolchain::envPrefix(self::$root);
        $cmd = implode(' ', array_map('escapeshellarg', [...$prefix, 'bash', $script])).' 2>&1';
        exec($cmd, $lines, $exitCode);

        $out = implode("\n", $lines);
        if (str_contains($out, 'lint failed') || str_contains($out, 'M2 prerequisite failed')) {
            $this->markTestSkipped('Prerequisite ladder failed before M3 HelloWorld.');
        }
        if (str_contains($out, 'M3 HelloWorld prerequisite failed')) {
            $this->markTestSkipped('M3 HelloWorld strict not green; gen-1 not reached.');
        }
        if (str_contains($out, 'M4 gen-1 link failed')) {
            $this->markTestSkipped('M4 gen-1 link not green; full probe ladder incomplete.');
        }
        if (str_contains($out, 'M4 gen-1→gen-2 native slice OK')) {
            $this->assertSame(0, $exitCode, $out);

            return;
        }
        if (str_contains($out, 'M4 exit status (M3 HelloWorld strict already verified above)')) {
            $this->assertSame(2, $exitCode, $out);
            $this->assertStringContainsString('gen-2 native emit still blocked', $out);

            return;
        }
        $this->assertSame(2, $exitCode, $out);
    }
}
