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
        $this->assertStringContainsString('BOOTSTRAP_M3_HELLOWORLD_STRICT=1', $script);
        $this->assertStringContainsString('gen-1→gen-2', $script);
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
    }

    public function testBootstrapLoopSmokeEntryDocumentsProbe(): void
    {
        $entry = (string) file_get_contents(self::$root.'/test/selfhost/bootstrap_loop_smoke/main.php');
        $this->assertStringContainsString('bootstrap-loop-probe', $entry);
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
        $this->assertSame(0, $exitCode, $out);
        $this->assertStringContainsString('--dry-run OK', $out);
        $this->assertStringContainsString('M3 strict native emit', $out);
        $this->assertStringNotContainsString('M3 native-emit prerequisite (strict)', $out);
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
        if (str_contains($out, 'M3 strict prerequisite OK')) {
            $this->assertSame(0, $exitCode, $out);
            $this->assertStringContainsString('gen-1→gen-2 rebuild not implemented', $out);

            return;
        }
        $this->assertSame(2, $exitCode, $out);
        $this->assertStringContainsString('M4 blocked', $out);
        $this->assertStringContainsString('M3 strict native emit', $out);
    }
}
