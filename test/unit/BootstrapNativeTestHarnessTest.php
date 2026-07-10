<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @group bootstrap */
final class BootstrapNativeTestHarnessTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testNativeTestHarnessScriptExists(): void
    {
        $script = self::$root.'/script/bootstrap-native-test.sh';
        $this->assertFileExists($script);
        $this->assertFileIsReadable($script);
    }

    public function testNativeTestHarnessScriptDocumentsUsage(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-native-test.sh');
        $this->assertStringContainsString('Usage:', $script);
        $this->assertStringContainsString('make bootstrap-native-test', $script);
        $this->assertStringContainsString('#15599', $script);
        $this->assertStringContainsString('Exit codes:', $script);
        $this->assertStringContainsString('exit 2', $script);
        $this->assertStringContainsString('compiler_smoke_standalone.php', $script);
        $this->assertStringContainsString('BOOTSTRAP_NO_ZEND_FALLBACK=1', $script);
        $this->assertStringContainsString('bootstrap-ensure-inventory-argv-driver.sh', $script);
        $this->assertStringContainsString('bootstrap_compile_invoke will try native candidates', $script);
        $this->assertStringContainsString('bootstrap-resolve-compile-invoke.sh', $script);
    }

    public function testNativeTestHarnessFixtureExists(): void
    {
        $entry = self::$root.'/test/bootstrap-aot/native_test_harness_smoke.php';
        $this->assertFileExists($entry);
        $body = (string) file_get_contents($entry);
        $this->assertStringContainsString('compiler_smoke_standalone.php', $body);
        $this->assertStringContainsString('#15599', $body);

        $fixture = self::$root.'/test/bootstrap-aot/compiler_smoke_standalone.php';
        $this->assertFileExists($fixture);
    }

    public function testMakefileDefinesBootstrapNativeTestTarget(): void
    {
        $makefile = (string) file_get_contents(self::$root.'/Makefile');
        $this->assertStringContainsString('bootstrap-native-test:', $makefile);
        $this->assertStringContainsString('./script/bootstrap-native-test.sh', $makefile);
        $this->assertStringContainsString('bootstrap-native-test-subset:', $makefile);
        $this->assertStringContainsString('./script/bootstrap-native-test-subset.sh', $makefile);
    }

    public function testBootstrapDevWorkflowDocumentsNativeTestHarnessTier(): void
    {
        $doc = (string) file_get_contents(self::$root.'/docs/bootstrap-dev-workflow.md');
        $this->assertStringContainsString('bootstrap-native-test', $doc);
        $this->assertStringContainsString('phpc test --native', $doc);
        $this->assertStringContainsString('#15599', $doc);
        $this->assertStringContainsString('Tier 1.5', $doc);
    }

    public function testNativeTestHarnessSmokePassesWhenLlvmPresent(): void
    {
        if (!LlvmToolchain::isReady(self::$root)) {
            $this->markTestSkipped('LLVM 9 not available for native test harness smoke.');
        }

        $script = self::$root.'/script/bootstrap-native-test.sh';
        $prefix = LlvmToolchain::envPrefix(self::$root);
        $cmd = implode(' ', array_map('escapeshellarg', [...$prefix, 'bash', $script])).' 2>&1';
        exec($cmd, $lines, $exitCode);

        $out = implode("\n", $lines);
        $this->assertSame(0, $exitCode, $out);
        $this->assertStringContainsString('bootstrap-native-test: OK', $out);
        $this->assertStringContainsString('compiler_smoke_standalone.php', $out);
        $binary = self::$root.'/build/native-test-harness-smoke';
        $this->assertTrue(is_executable($binary), $binary);
        $runOut = shell_exec($binary);
        $this->assertIsString($runOut);
        $this->assertSame('compiler smoke', trim(str_replace("\n", '', $runOut)));
    }
}
