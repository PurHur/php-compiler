<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @group aot-lint */
final class BootstrapLibSpineVmSmokeTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testVmSpineSmokeScriptExists(): void
    {
        $script = self::$root.'/script/bootstrap-selfhost-lib-spine-vm-smoke.sh';
        $this->assertFileExists($script);
        $this->assertFileIsReadable($script);
    }

    public function testVmSpineSmokeScriptDocumentsEnvAndArtifact(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-lib-spine-vm-smoke.sh');
        $this->assertStringContainsString('PHP_COMPILER_VM_SPINE_SMOKE=1', $script);
        $this->assertStringContainsString('selfhost-lib-spine-smoke', $script);
        $this->assertStringContainsString("grep -Fxq '1'", $script);
        $this->assertStringContainsString('bootstrap-selfhost-lib-spine-smoke-link.sh', $script);
    }

    public function testLibSpineLinkScriptDocumentsCrashDiag(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-lib-spine-smoke-link.sh');
        $this->assertStringContainsString('BOOTSTRAP_SPINE_CRASH_DIAG', $script);
        $this->assertStringContainsString('bootstrap_spine_emit_crash_diag', $script);
    }

    public function testLibSpineLintScriptUsesLlvmMemoryAndSpineEntry(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-lib-spine-smoke-lint.sh');
        $this->assertStringContainsString('ci_apply_llvm_memory_env', $script);
        $this->assertStringContainsString('compiler_lib_spine_smoke/main.php', $script);
        $this->assertStringContainsString('bootstrap-selfhost-lib-spine-smoke-lint: OK', $script);
    }

    public function testCompilePhpSkipsSourceBundlerForSpineLint(): void
    {
        $compile = (string) file_get_contents(self::$root.'/bin/compile.php');
        $this->assertStringContainsString('phpc_compile_skip_aot_bundle', $compile);
        $this->assertStringContainsString('compiler_lib_spine_smoke/main.php', $compile);
        $this->assertStringContainsString('!$skipBundle', $compile);
    }

    public function testLibSpineLinkScriptSeedsSidecarsAndRefusesZendFallback(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-lib-spine-smoke-link.sh');
        $this->assertStringContainsString('bootstrap-gen0-install-prelinked-driver.sh', $script);
        $this->assertStringContainsString('ci_ensure_vendor_patches', $script);
        $this->assertStringContainsString('bootstrap_ensure_m3_compiler_lib_sidecar', $script);
        $this->assertStringContainsString('export BOOTSTRAP_NO_ZEND_FALLBACK=1', $script);
        $this->assertStringContainsString('inventory argv driver unavailable (no Zend — #8716)', $script);
        $this->assertStringContainsString('BOOTSTRAP_LIB_SPINE_SMOKE_GEN0_FALLBACK', $script);
        $this->assertStringContainsString('BOOTSTRAP_NO_ZEND_FALLBACK:-0}" != "1"', $script);
        $this->assertStringContainsString('vm_driver_probe_ok=1', $script);
        $this->assertStringContainsString('vm_driver_probe_ok}" == "1"', $script);
    }

    public function testSpineEntryBundlesBinVmPhp(): void
    {
        $entry = (string) file_get_contents(self::$root.'/test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('bin/vm.php', $entry);
        $this->assertStringContainsString('PHP_COMPILER_LIB_SPINE_SMOKE', $entry);
        $this->assertStringContainsString('PHP_COMPILER_VM_SPINE_SMOKE', $entry);
        $this->assertStringContainsString("run('Standard input code', '<?php echo \"1\\n\";', [])", $entry);
        $this->assertStringNotContainsString('vm-spine-ok', $entry);
        $this->assertStringContainsString('bootstrap-selfhost-lib-spine-vm-smoke', $entry);
    }

    public function testSpineEntryExercisesMbMimeheaderPaths(): void
    {
        $entry = (string) file_get_contents(self::$root.'/test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('#8697', $entry);
        $this->assertStringContainsString('VmMbstring::encodeMimeheader', $entry);
        $this->assertStringContainsString('VmMbstring::decodeMimeheader', $entry);
        $this->assertStringContainsString('JitMbMimeheader.php', $entry);
        $this->assertStringContainsString('mb_encode_mimeheader.php', $entry);
        $this->assertStringContainsString('mb_decode_mimeheader.php', $entry);
        // Honest full-spine AOT: call builtins, not VmMbstring::encodeMimeheader() STATICCALL (#22642 r13).
        $this->assertStringContainsString("mb_encode_mimeheader('Hello", $entry);
        $this->assertStringContainsString('mb_decode_mimeheader($__spineMimeEnc)', $entry);
        $this->assertStringNotContainsString(
            '\\PHPCompiler\\ext\\mbstring\\VmMbstring::encodeMimeheader(',
            $entry
        );
        $this->assertStringNotContainsString(
            '\\PHPCompiler\\ext\\mbstring\\VmMbstring::decodeMimeheader(',
            $entry
        );
    }

    public function testSpineEntryExercisesSetcookieOptionsParseArgs(): void
    {
        $entry = (string) file_get_contents(self::$root.'/test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('#8698', $entry);
        $this->assertStringContainsString('SetcookieOptions::spineSmokeParse', $entry);
        $this->assertStringContainsString('JitSetcookieOptions.php', $entry);
        $this->assertStringContainsString('SetcookieOptions.php', $entry);
        $options = (string) file_get_contents(self::$root.'/ext/standard/SetcookieOptions.php');
        $this->assertStringContainsString('spineSmokeParse', $options);
        $this->assertStringContainsString("self::parseArgs('setcookie'", $options);
    }

    public function testVmDriverExecuteNativeDoesNotStubVmSpineSmokeAtMainEntry(): void
    {
        $native = (string) file_get_contents(self::$root.'/lib/JIT/VmDriverExecuteNative.php');
        $this->assertStringNotContainsString('vm-spine-ok', $native);
        $this->assertStringContainsString('emitRunProbeEcho', $native);
    }

    public function testMakefileDefinesVmSpineSmokeTarget(): void
    {
        $makefile = (string) file_get_contents(self::$root.'/Makefile');
        $this->assertStringContainsString('bootstrap-selfhost-lib-spine-vm-smoke:', $makefile);
    }

    public function testCliDriverSkipsArgvWhenVmSpineSmoke(): void
    {
        $driver = (string) file_get_contents(self::$root.'/src/cli_driver.php');
        $this->assertStringContainsString('PHP_COMPILER_VM_SPINE_SMOKE', $driver);
    }

    public function testSpineSmokeRoutesThroughMainNotNativeEchoStub(): void
    {
        $native = (string) file_get_contents(self::$root.'/lib/JIT/VmDriverExecuteNative.php');
        $this->assertStringContainsString('#8719', $native);
        $this->assertStringContainsString('honest -r via main() → run()', $native);
        $entry = (string) file_get_contents(self::$root.'/test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString("run('Standard input code', '<?php echo \"1\\n\";', [])", $entry);
        $this->assertStringNotContainsString("echo \"vm-spine-ok\\n\";", $entry);
    }

    public function testWaveCheckDocumentsVmSpineSmokeFlag(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-wave-check.sh');
        $this->assertStringContainsString('--with-lib-spine-vm-smoke', $script);
        $this->assertStringContainsString('BOOTSTRAP_LIB_SPINE_VM_SMOKE', $script);
    }

    public function testCiDefaultsEnvDefinesVmSpineSmokeGateOn(): void
    {
        $defaults = (string) file_get_contents(self::$root.'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'BOOTSTRAP_LIB_SPINE_VM_SMOKE_GATE="${BOOTSTRAP_LIB_SPINE_VM_SMOKE_GATE:-1}"',
            $defaults
        );
    }
}
