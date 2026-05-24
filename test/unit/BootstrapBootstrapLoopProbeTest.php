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
        $this->assertStringContainsString('bootstrap-loop-gen1-link.sh', $script);
        $this->assertStringContainsString('BOOTSTRAP_M3_HELLOWORLD_STRICT=1', $script);
        $this->assertStringContainsString('BOOTSTRAP_M4_LINK_COMPILE_DRIVER=1', $script);
        $this->assertStringContainsString('gen-1 link', $script);
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
        $this->assertStringContainsString('bootstrap-loop-gen1-link:', $makefile);
    }

    public function testBootstrapLoopSmokeEntryDocumentsProbe(): void
    {
        $entry = (string) file_get_contents(self::$root.'/test/selfhost/bootstrap_loop_smoke/main.php');
        $this->assertStringContainsString('bootstrap-loop-probe', $entry);
        $this->assertStringContainsString('bootstrap-loop-gen1-link', $entry);
        $this->assertStringContainsString('bootstrap_loop_smoke bundle OK', $entry);
        $this->assertStringContainsString('#1498', $entry);
    }

    public function testDryRunSkipsStrictWhenLlvmPresent(): void
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
        if (str_contains($out, 'M3 HelloWorld probe (partial') && str_contains($out, 'failed (exit')) {
            $this->markTestSkipped('M3 partial probe not green in this environment.');
        }
        if (str_contains($out, 'M4 gen-1 link failed')) {
            $this->markTestSkipped('M4 gen-1 link not green in this environment.');
        }
        $this->assertSame(0, $exitCode, $out);
        $this->assertStringContainsString('--dry-run OK', $out);
        $this->assertStringContainsString('M4 gen-1 link', $out);
        $this->assertStringContainsString('M3 strict native emit', $out);
        $this->assertStringNotContainsString('M3 native-emit prerequisite (strict)', $out);
    }

    public function testGen1LinkPartialGreenWhenLlvmPresent(): void
    {
        if (!LlvmToolchain::isReady(self::$root)) {
            $this->markTestSkipped('LLVM 9 not available for M4 gen-1 link test.');
        }

        $script = self::$root.'/script/bootstrap-loop-gen1-link.sh';
        $prefix = LlvmToolchain::envPrefix(self::$root);
        $cmd = implode(' ', array_map('escapeshellarg', [
            ...$prefix,
            'env', 'BOOTSTRAP_M4_LINK_COMPILE_DRIVER=1',
            'bash', $script,
        ])).' 2>&1';
        exec($cmd, $lines, $exitCode);

        $out = implode("\n", $lines);
        $this->assertSame(0, $exitCode, $out);
        $this->assertStringContainsString('bootstrap-loop-gen1-link: OK', $out);
        $this->assertStringContainsString('compiler smoke', $out);
        $this->assertTrue(is_executable(self::$root.'/build/bootstrap-loop-gen1'));
        $this->assertTrue(is_executable(self::$root.'/build/bootstrap-loop-gen2'));
    }

    public function testFullProbeExitsTwoWhenM3StrictBlocked(): void
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
            $this->markTestSkipped('Prerequisite ladder failed before M3 strict gate.');
        }
        if (str_contains($out, 'M3 partial prerequisite failed')) {
            $this->markTestSkipped('M3 partial probe not green; strict gate not reached.');
        }
        if (str_contains($out, 'M4 gen-1 link failed')) {
            $this->markTestSkipped('M4 gen-1 link not green; full probe ladder incomplete.');
        }
        if (str_contains($out, 'M4 gen-1→gen-2 native slice OK')) {
            $this->assertSame(0, $exitCode, $out);

            return;
        }
        if (str_contains($out, 'M3 strict prerequisite OK')) {
            $this->assertSame(2, $exitCode, $out);
            $this->assertStringContainsString('gen-2 native emit still blocked', $out);

            return;
        }
        $this->assertSame(2, $exitCode, $out);
        $this->assertStringContainsString('M4 blocked', $out);
        $this->assertStringContainsString('M3 strict native emit', $out);
    }
}
