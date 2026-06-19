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
        $this->assertStringContainsString('bootstrap_gen0_copy_prelinked_inventory_driver', $link);
        $this->assertMatchesRegularExpression(
            '/BOOTSTRAP_M5_NO_ZEND[\s\S]*bootstrap_gen0_install_prelinked_driver[\s\S]*bootstrap_resolve_compile_driver/s',
            $link,
            'M5 cold boot installs prelinked gen-0 before compile driver resolve (#3053)'
        );
        $install = (string) file_get_contents(self::$root.'/script/bootstrap-gen0-install-prelinked-driver.sh');
        $this->assertStringContainsString('prelinked/bootstrap-gen0/bin-compile-aot', $install);
        $this->assertStringContainsString('bootstrap_gen0_copy_prelinked_inventory_driver', $install);
        $this->assertStringContainsString('bootstrap_gen0_installed_driver_matches_prelinked', $install);
        $this->assertStringContainsString('stale build/bin-compile-aot', $install);
        $this->assertStringContainsString('#2930', $install);
        $this->assertStringContainsString('compiler_minimal_aot_blob', $install);
        $this->assertStringContainsString('bootstrap_ensure_m3_compiler_lib_sidecar', $install);
        $this->assertStringContainsString('bootstrap_ensure_prelinked_sidecar_path_symlink', $install);
        $this->assertStringContainsString('.m3_compiler_lib_aot_blob', $install);
        $this->assertStringContainsString('bootstrap_compiler_lib_spine_entry_sha', $install);
        $this->assertStringContainsString('bootstrap_prelinked_gen0_compiler_lib_stamp_stale', $install);
        $this->assertStringContainsString('bootstrap_gen3_emit_matches_stale_prelinked_gen0', $install);
        $this->assertStringContainsString('PHP_COMPILER_M3_SIDECAR_HOST=1', $install);
        $this->assertStringNotContainsString('PHP_COMPILER_CLI_SPINE_BUNDLE=1', $install);
        $this->assertFileExists(self::$root.'/prelinked/bootstrap-gen0/bin-compile-aot');
        $this->assertFileExists(self::$root.'/prelinked/bootstrap-gen0/compiler_minimal_aot_blob');
        $this->assertFileExists(self::$root.'/prelinked/bootstrap-gen0/compiler_lib_aot_blob');
        $this->assertFileExists(self::$root.'/prelinked/bootstrap-gen0/.m3_bootstrap_loop_smoke_main_aot_blob');
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
        $this->assertStringContainsString('bootstrap_loop_smoke/main.php', $body);
        $this->assertStringContainsString('.m3_bootstrap_loop_smoke_main_aot_blob', $body);
        $this->assertStringContainsString('gen-0 sidecar emit fallback', $body);
        $this->assertStringContainsString('native parse spine null', $body);
        $this->assertStringContainsString('gen-0 native emit failed — recovered via sidecar', $body);
        $this->assertStringContainsString('bootstrap_is_gen0_prelinked_seed_driver', $body);
        $this->assertStringContainsString('bootstrap_gen0_seed_prelinked_m3_sidecars', $body);
        $this->assertStringContainsString('bootstrap_inventory_argv_driver_m4_smoke', $body);
        $this->assertStringContainsString('bootstrap_ensure_m3_compiler_lib_sidecar', $body);
        $gen0 = (string) file_get_contents(self::$root.'/script/bootstrap-gen0-install-prelinked-driver.sh');
        $this->assertStringContainsString('prelinked/bootstrap-gen0/.m3_', $gen0);
        $this->assertStringContainsString('bootstrap_gen0_prelinked_sidecar_looks_stale', $gen0);
        $this->assertStringContainsString('#3046', $gen0);
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
        $this->assertStringContainsString('bootstrap_inventory_argv_link', $body);
        $this->assertStringContainsString('compiled-first', $body);
        $this->assertStringContainsString('(gen-0 compiled)', $body);
        $this->assertStringContainsString('(gen-0 Zend)', $body);
        $this->assertStringContainsString('BOOTSTRAP_ALLOW_GEN0_ZEND', $body);
        $this->assertStringContainsString('failed (exit', $body);
        $this->assertStringContainsString('falling back to Zend gen-0', $body);
        $this->assertStringContainsString('BOOTSTRAP_M5_NO_ZEND', $body);
        $this->assertStringContainsString(
            'BOOTSTRAP_NO_ZEND_FALLBACK=1 — prelinked inventory driver failed smoke',
            $body,
            'inventory argv driver refuses Zend rebuild when no-Zend fallback (#8716, #3053)'
        );

        $spineLink = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-lib-spine-smoke-link.sh');
        $this->assertStringContainsString('export BOOTSTRAP_NO_ZEND_FALLBACK=1', $spineLink);
        $this->assertStringContainsString('inventory argv driver unavailable (no Zend — #8716)', $spineLink);
        $this->assertStringContainsString('BOOTSTRAP_NO_ZEND_FALLBACK:-0}" != "1"', $spineLink);

        $coldBoot = self::$root.'/script/bootstrap-selfhost-cold-boot-probe.sh';
        $this->assertFileExists($coldBoot);
        $this->assertStringContainsString('BOOTSTRAP_M5_NO_ZEND=1', (string) file_get_contents($coldBoot));

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
