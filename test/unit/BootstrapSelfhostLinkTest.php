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
        $this->assertStringContainsString('bootstrap_gen0_copy_prelinked_inventory_driver', $install);
        $this->assertStringContainsString('bootstrap_gen0_installed_driver_matches_prelinked', $install);
        $this->assertStringContainsString('stale build/bin-compile-aot', $install);
        $this->assertStringContainsString('#2930', $install);
        $this->assertStringContainsString('compiler_minimal_aot_blob', $install);
        $this->assertStringContainsString('bootstrap_ensure_m3_compiler_lib_sidecar', $install);
        $this->assertStringContainsString('.m3_compiler_lib_aot_blob', $install);
        $this->assertStringContainsString('bootstrap_compiler_lib_spine_entry_sha', $install);
        $this->assertStringContainsString('PHP_COMPILER_M3_SIDECAR_HOST=1', $install);
        $this->assertStringNotContainsString('PHP_COMPILER_CLI_SPINE_BUNDLE=1', $install);
        $this->assertFileExists(self::$root.'/prelinked/bootstrap-gen0/bin-compile-aot');
        $this->assertFileExists(self::$root.'/prelinked/bootstrap-gen0/compiler_minimal_aot_blob');
        $this->assertFileExists(self::$root.'/prelinked/bootstrap-gen0/compiler_lib_aot_blob');
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
        $this->assertStringContainsString('build/bin-compile-aot-inventory', $body);
        $this->assertStringContainsString('exited 0 but missing', $body);
        $this->assertStringContainsString('bootstrap_gen0_sidecar_emit_fallback', $body);
        $this->assertStringContainsString('gen-0 sidecar emit fallback', $body);
        $this->assertStringContainsString('native parse spine null', $body);
        $this->assertStringContainsString('bootstrap_gen0_seed_prelinked_m3_sidecars', $body);
        $this->assertStringContainsString('bootstrap_inventory_argv_driver_m4_smoke', $body);
        $this->assertStringContainsString('bootstrap_ensure_m3_compiler_lib_sidecar', $body);
        $gen0 = (string) file_get_contents(self::$root.'/script/bootstrap-gen0-install-prelinked-driver.sh');
        $this->assertStringContainsString('prelinked/bootstrap-gen0/.m3_', $gen0);
        $this->assertStringContainsString('#2880', $gen0);
        $this->assertStringContainsString('build/selfhost-compile-driver', $body);
        $this->assertStringContainsString('build/bin-compile-aot', $body);
        $this->assertStringContainsString(
            '"${root}/build/bin-compile-aot-inventory" \\'."\n"
            .'    "${root}/build/bin-compile-aot" \\',
            $body,
            'inventory argv driver listed before bin-compile-aot (#2894)'
        );
        $this->assertStringContainsString(
            '"${root}/build/bin-compile-aot" \\'."\n"
            .'    "${root}/build/selfhost-native-compile-driver" \\',
            $body,
            'prefer bin-compile-aot before emit-helper alias (#2894)'
        );
        $this->assertStringContainsString(
            '"${root}/build/selfhost-native-compile-driver" \\'."\n"
            .'    "${root}/build/selfhost-helloworld-compile" \\',
            $body
        );
        $this->assertStringContainsString(
            '"${root}/build/selfhost-helloworld-compile" \\'."\n"
            .'    "${root}/build/selfhost-compile-driver"',
            $body
        );
        $this->assertStringContainsString('(gen-0 compiled)', $body);
        $this->assertStringContainsString('(gen-0 Zend)', $body);
        $this->assertStringContainsString('BOOTSTRAP_ALLOW_GEN0_ZEND', $body);
        $this->assertStringContainsString('failed (exit', $body);
        $this->assertStringContainsString('falling back to Zend gen-0', $body);
        $this->assertStringContainsString('BOOTSTRAP_M5_NO_ZEND', $body);

        $dockerExec = (string) file_get_contents(self::$root.'/script/docker-exec.sh');
        $this->assertStringContainsString('bootstrap-selfhost-link', $dockerExec);
        $this->assertStringContainsString('build/bin-compile-aot-inventory', $dockerExec);
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
