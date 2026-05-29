<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @group aot-lint */
final class BootstrapSelfhostLinkTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testLinkScriptSurfacesApplyPatchesFailure(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-link.sh');
        $this->assertStringContainsString('apply-patches failed (#2806)', $script);
        $this->assertStringNotContainsString('apply-patches.sh" >/dev/null', $script);
    }

    public function testLinkScriptInstallsPrelinkedGen0Driver(): void
    {
        $link = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-link.sh');
        $this->assertStringContainsString('bootstrap-gen0-install-prelinked-driver.sh', $link);
        $this->assertStringContainsString('bootstrap_gen0_install_prelinked_driver', $link);
        $this->assertStringContainsString('BOOTSTRAP_M5_NO_ZEND', $link);
        $install = (string) file_get_contents(self::$root.'/script/bootstrap-gen0-install-prelinked-driver.sh');
        $this->assertStringContainsString('prelinked/bootstrap-gen0/bin-compile-aot', $install);
        $this->assertStringContainsString('compiler_minimal_aot_blob', $install);
        $this->assertFileExists(self::$root.'/prelinked/bootstrap-gen0/bin-compile-aot');
        $this->assertFileExists(self::$root.'/prelinked/bootstrap-gen0/compiler_minimal_aot_blob');
    }

    public function testLinkScriptUsesCompiledDriverResolver(): void
    {
        $link = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-link.sh');
        $this->assertStringContainsString('bootstrap-resolve-compile-invoke.sh', $link);
        $this->assertStringContainsString('bootstrap_compile_invoke', $link);
        $this->assertStringContainsString('BOOTSTRAP_GEN0_ENSURE_COMPILED_DRIVER', $link);
        $resolver = self::$root.'/script/bootstrap-resolve-compile-invoke.sh';
        $this->assertFileExists($resolver);
        $body = (string) file_get_contents($resolver);
        $this->assertStringContainsString('build/selfhost-compile-driver', $body);
        $this->assertStringContainsString('build/bin-compile-aot', $body);
        $driverPos = strpos($body, 'build/selfhost-compile-driver');
        $argvPos = strpos($body, 'build/bin-compile-aot');
        $nativeAliasPos = strpos($body, 'build/selfhost-native-compile-driver');
        $this->assertNotFalse($driverPos);
        $this->assertNotFalse($argvPos);
        $this->assertNotFalse($nativeAliasPos);
        $this->assertLessThan($driverPos, $argvPos, 'prefer bin-compile-aot before selfhost-compile-driver (#2894)');
        $this->assertLessThan($nativeAliasPos, $argvPos, 'prefer bin-compile-aot before emit-helper alias (#2894)');
        $this->assertStringContainsString('failed (exit', $body);
        $this->assertStringContainsString('falling back to Zend gen-0', $body);
        $this->assertStringContainsString('BOOTSTRAP_M5_NO_ZEND', $body);
    }

    public function testNativeLinkScriptPrintsBundleOkWhenLlvmPresent(): void
    {
        if (!LlvmToolchain::isReady(self::$root)) {
            $this->markTestSkipped('LLVM 9 not available for self-host native link smoke test.');
        }

        $script = self::$root.'/script/bootstrap-selfhost-link.sh';
        $this->assertFileExists($script);

        $prefix = LlvmToolchain::envPrefix(self::$root);
        $cmd = implode(' ', array_map('escapeshellarg', [...$prefix, 'bash', $script])).' 2>&1';
        exec($cmd, $lines, $exitCode);

        $out = implode("\n", $lines);
        $this->assertSame(0, $exitCode, $out);
        $this->assertStringContainsString('bootstrap-selfhost-link: OK', $out);
        $binary = self::$root.'/build/selfhost';
        $this->assertTrue(is_executable($binary), $binary);
        $runOut = shell_exec(self::$root.'/build/selfhost');
        $this->assertIsString($runOut);
        $this->assertSame('compiler_minimal bundle OK', trim(str_replace("\n", '', $runOut)));
    }
}
