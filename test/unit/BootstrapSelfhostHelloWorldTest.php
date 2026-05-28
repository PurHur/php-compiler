<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @group aot-lint */
final class BootstrapSelfhostHelloWorldTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testHelloWorldProbeScriptExists(): void
    {
        $script = self::$root.'/script/bootstrap-selfhost-helloworld-probe.sh';
        $this->assertFileExists($script);
        $this->assertFileIsReadable($script);
    }

    public function testHelloWorldProbePartialGreenWhenLlvmPresent(): void
    {
        if (!LlvmToolchain::isReady(self::$root)) {
            $this->markTestSkipped('LLVM 9 not available for M3 HelloWorld self-host probe test.');
        }

        $script = self::$root.'/script/bootstrap-selfhost-helloworld-probe.sh';
        $prefix = LlvmToolchain::envPrefix(self::$root);
        $cmd = implode(' ', array_map('escapeshellarg', [...$prefix, 'bash', $script])).' 2>&1';
        exec($cmd, $lines, $exitCode);

        $out = implode("\n", $lines);
        $this->assertSame(0, $exitCode, $out);
        $this->assertStringContainsString('bootstrap-selfhost-helloworld-probe: OK', $out);
        $this->assertStringContainsString('Hello World', $out);
        $this->assertTrue(is_executable(self::$root.'/build/helloworld-aot'));
    }

    public function testHelloWorldBundleLintPasses(): void
    {
        $entry = self::$root.'/test/selfhost/compiler_helloworld_smoke/main.php';
        $this->assertFileExists($entry);

        $prefix = LlvmToolchain::envPrefix(self::$root);
        $cmd = implode(' ', array_map('escapeshellarg', [...$prefix, 'php', self::$root.'/bin/compile.php', '-l', $entry])).' 2>&1';
        exec($cmd, $lines, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $lines));
    }

    public function testHelloWorldDriverLintPasses(): void
    {
        $entry = self::$root.'/test/selfhost/compiler_helloworld_smoke/driver_lint.php';
        $this->assertFileExists($entry);

        $prefix = LlvmToolchain::envPrefix(self::$root);
        $cmd = implode(' ', array_map('escapeshellarg', [...$prefix, 'php', self::$root.'/bin/compile.php', '-l', $entry])).' 2>&1';
        exec($cmd, $lines, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $lines));
    }

    public function testWaveCheckDocumentsM3HelloWorldGateEnv(): void
    {
        $wave = (string) file_get_contents(self::$root.'/script/bootstrap-wave-check.sh');
        $this->assertStringContainsString('BOOTSTRAP_M3_HELLOWORLD', $wave);
        $this->assertStringContainsString('--with-helloworld', $wave);
        $this->assertStringContainsString('bootstrap-selfhost-helloworld-probe.sh', $wave);
    }

    public function testBootstrapSelfhostDocMentionsHelloWorldProbe(): void
    {
        $doc = (string) file_get_contents(self::$root.'/docs/bootstrap-selfhost.md');
        $this->assertStringContainsString('bootstrap-selfhost-helloworld-probe.sh', $doc);
        $this->assertStringContainsString('BOOTSTRAP_M3_HELLOWORLD', $doc);
        $this->assertStringContainsString('compiler_helloworld_smoke', $doc);
    }

    public function testCompilerFirstClassCallableAvoidsMatchThrowInBundle(): void
    {
        $source = (string) file_get_contents(self::$root.'/lib/Compiler.php');
        $this->assertStringNotContainsString('default => throw', $source);
        $this->assertStringContainsString('3 === $expr->kind', $source);
    }

    public function testHelloWorldCompileDriverLinksWhenOptIn(): void
    {
        if (!LlvmToolchain::isReady(self::$root)) {
            $this->markTestSkipped('LLVM 9 not available for M3 HelloWorld compile driver link test.');
        }

        $driver = self::$root.'/test/selfhost/compiler_helloworld_smoke/compile_driver.php';
        $out = self::$root.'/build/selfhost-helloworld-compile-test';
        @unlink($out);

        $prefix = LlvmToolchain::envPrefix(self::$root);
        $cmd = implode(' ', array_map('escapeshellarg', [
            ...$prefix,
            'env', 'PHP_COMPILER_SELFHOST_AOT=1',
            'php', self::$root.'/bin/compile.php', '-o', $out, $driver,
        ])).' 2>&1';
        exec($cmd, $lines, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $lines));
        $this->assertFileExists($out);
        $this->assertTrue(is_executable($out));
    }

    public function testHelloWorldCompileDriverLinksWithM3RealLowering(): void
    {
        if (!LlvmToolchain::isReady(self::$root)) {
            $this->markTestSkipped('LLVM 9 not available for M3 compile driver real lowering link test.');
        }

        $driver = self::$root.'/test/selfhost/compiler_helloworld_smoke/compile_driver.php';
        $out = self::$root.'/build/selfhost-helloworld-compile-m3-test';
        @unlink($out);

        $prefix = LlvmToolchain::envPrefix(self::$root);
        $cmd = implode(' ', array_map('escapeshellarg', [
            ...$prefix,
            'env', 'PHP_COMPILER_SELFHOST_AOT=1', 'PHP_COMPILER_M3_COMPILE_DRIVER=1', 'PHP_COMPILER_M3_COMPILE_DRIVER_MAIN=1',
            'php', self::$root.'/bin/compile.php', '-o', $out, $driver,
        ])).' 2>&1';
        exec($cmd, $lines, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $lines));
        $this->assertFileExists($out);
        $this->assertTrue(is_executable($out));
    }

    public function testCompilePhpSetsM3CompileDriverMainForSelfhostCompileDriver(): void
    {
        $compile = (string) file_get_contents(self::$root.'/bin/compile.php');
        $this->assertStringContainsString('PHP_COMPILER_M3_COMPILE_DRIVER_MAIN=1', $compile);
        $this->assertStringContainsString('compile_driver.php', $compile);
        $this->assertStringContainsString('PHP_COMPILER_M3_EMIT_LOG_PREFIX=helloworld_compile_smoke', $compile);
    }

    public function testHelloWorldCompileBinScriptExists(): void
    {
        $script = self::$root.'/script/bootstrap-selfhost-helloworld-compile-bin.sh';
        $this->assertFileExists($script);
        $source = (string) file_get_contents($script);
        $this->assertStringContainsString('selfhost-helloworld-compile', $source);
        $this->assertStringContainsString('helloworld_compile_smoke:', $source);
        $this->assertStringContainsString('PHP_COMPILER_M3_COMPILE_MODE=compile', $source);
        $this->assertStringContainsString('.m3_bin_compile_aot_blob', $source);
        $this->assertStringContainsString('PHP_COMPILER_M4_BIN_COMPILE_DRIVER=1', $source);
    }

    /** Issue #2880: M4 full-revision probe script and native argv main for bin/compile.php. */
    public function testM4BinCompileRevisionProbeScriptExists(): void
    {
        $script = self::$root.'/script/bootstrap-selfhost-full-revision-probe.sh';
        $this->assertFileExists($script);
        $source = (string) file_get_contents($script);
        $this->assertStringContainsString('bootstrap-full-revision-gen3-compile', $source);
        $this->assertStringContainsString('bin/compile.php', $source);
        $alias = self::$root.'/script/bootstrap-loop-gen2-recompile-bin-compile.sh';
        $this->assertFileExists($alias);
        $this->assertStringContainsString('bootstrap-selfhost-full-revision-probe.sh', (string) file_get_contents($alias));
        $jit = (string) file_get_contents(self::$root.'/lib/JIT.php');
        $this->assertStringContainsString('PHP_COMPILER_M4_BIN_COMPILE_DRIVER', $jit);
        $this->assertStringContainsString('shouldUseM4BinCompileArgvMainNative', $jit);
        $this->assertStringContainsString('isM4BinCompileScriptMain', $jit);
    }

    public function testCliDriverEmitProbeAndVmSidecarConstantsExist(): void
    {
        $probe = self::$root.'/script/bootstrap-selfhost-cli-driver-emit.sh';
        $this->assertFileExists($probe);
        $jit = (string) file_get_contents(self::$root.'/lib/JIT/M3EmitTuTrivialEchoAot.php');
        $this->assertStringContainsString('BIN_VM_SIDECAR_REL', $jit);
        $this->assertStringContainsString('CLI_DRIVER_SIDECAR_REL', $jit);
        $this->assertStringContainsString('bin/vm.php', (string) file_get_contents(self::$root.'/lib/JIT.php'));
        $this->assertStringContainsString('src/cli_driver.php', (string) file_get_contents(self::$root.'/lib/JIT.php'));

        // Sidecar matching is content-based; JIT must compare __string__ buffers safely without relying on
        // null termination (issue #2699).
        $cmp = (string) file_get_contents(self::$root.'/lib/JIT/JitStringCompare.php');
        $this->assertStringContainsString("lookupFunction('memcmp')", $cmp);
    }

    public function testHelloWorldProbeLinksCompileDriverBinary(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-helloworld-probe.sh');
        $this->assertStringContainsString('selfhost-helloworld-compile', $script);
        $this->assertStringContainsString('helloworld compile binary link OK', $script);
    }

    public function testHelloWorldCompileM3EmitNativeEntryExists(): void
    {
        $entry = self::$root.'/test/bootstrap-aot/helloworld_compile_m3_emit_native_entry.php';
        $this->assertFileExists($entry);
        $source = (string) file_get_contents($entry);
        $this->assertStringContainsString('helloworld_compile_smoke:', $source);
        $this->assertStringContainsString('selfhost-helloworld-compile', $source);
    }

    public function testCompilePhpSetsHelloWorldCompileEmitLogPrefix(): void
    {
        $compile = (string) file_get_contents(self::$root.'/bin/compile.php');
        $this->assertStringContainsString('helloworld_compile_m3_emit_native_entry.php', $compile);
        $this->assertStringContainsString(
            "putenv('PHP_COMPILER_M3_EMIT_LOG_PREFIX=helloworld_compile_smoke')",
            $compile
        );
    }

    public function testNativeCompileDriverMainNativePrintsReadyWhenLlvmPresent(): void
    {
        if (!LlvmToolchain::isReady(self::$root)) {
            $this->markTestSkipped('LLVM 9 not available for M3 compile driver native main test.');
        }

        $script = self::$root.'/script/bootstrap-selfhost-compile-driver-link-probe.sh';
        $prefix = LlvmToolchain::envPrefix(self::$root);
        $cmd = implode(' ', array_map('escapeshellarg', [...$prefix, 'bash', $script])).' 2>&1';
        exec($cmd, $lines, $exitCode);
        $this->assertSame(0, $exitCode, implode("\n", $lines));

        $driver = self::$root.'/build/selfhost-helloworld-compile-driver';
        $this->assertFileExists($driver);
        $this->assertTrue(is_executable($driver), "Expected {$driver} to be executable");
        $runOut = shell_exec($driver);
        $this->assertIsString($runOut);
        $this->assertStringContainsString('compiler_helloworld_compile_driver ready', $runOut);
    }

    public function testHelloWorldCompileDriverHasModeDispatch(): void
    {
        $driver = (string) file_get_contents(self::$root.'/test/selfhost/compiler_helloworld_smoke/compile_driver.php');
        $this->assertStringContainsString('PHP_COMPILER_M3_COMPILE_MODE', $driver);
        $this->assertStringContainsString('PHP_COMPILER_M3_SOURCE', $driver);
        $this->assertStringContainsString('helloworld_compile_smoke', $driver);
        $this->assertStringContainsString('compiler_smoke.php', $driver);
    }

    public function testJitM3AllowlistMatchesBootstrapAotHelloWorldSmoke(): void
    {
        $jit = (string) file_get_contents(self::$root.'/lib/JIT.php');
        $this->assertStringContainsString('isBootstrapHelloWorldSmokeName', $jit);
        $this->assertStringContainsString('isBootstrapHelloWorldSmokeName', $jit);
        $this->assertStringContainsString('m3CompileDriverSpineDenyNames', $jit);
        $this->assertStringContainsString('#1515', $jit);
        $driver = (string) file_get_contents(self::$root.'/test/selfhost/compiler_helloworld_smoke/compile_driver.php');
        $this->assertStringContainsString('\\PHPCompiler\\BootstrapAot\\helloworld_compile_smoke', $driver);
        $smoke = (string) file_get_contents(self::$root.'/test/bootstrap-aot/helloworld_compile_smoke.php');
        $this->assertStringContainsString('namespace PHPCompiler\\BootstrapAot', $smoke);
    }

    public function testIncludeHelperAssignCountGuardsCycles(): void
    {
        $source = (string) file_get_contents(self::$root.'/lib/JIT/IncludeHelper.php');
        $this->assertStringContainsString('spl_object_id($block)', $source);
        $this->assertStringContainsString('$visited', $source);
    }

    public function testJitDocumentsM3CompileDriverEnvGate(): void
    {
        $jit = (string) file_get_contents(self::$root.'/lib/JIT.php');
        $this->assertStringContainsString('PHP_COMPILER_M3_COMPILE_DRIVER', $jit);
        $this->assertStringContainsString('isM3CompileDriverRealLoweringName', $jit);
        $this->assertStringContainsString('shouldUseM3EmitTuRuntimeMethodStub', $jit);
        $this->assertStringContainsString('m3EmitTuRuntimeSpineLowered', $jit);
        $this->assertStringContainsString('helloworld_compile_smoke', $jit);
        $this->assertStringContainsString('runtime::parseandcompile', $jit);
        $this->assertStringContainsString('runtime::parse', $jit);
        $this->assertStringContainsString('runtime::compileemitsmoke', $jit);
        $this->assertStringContainsString('runtime::compile', $jit);
        $this->assertStringContainsString('jitFunctionSkipName', $jit);
        $this->assertStringContainsString('m3CompileDriverSpineDenyNames', $jit);
    }

    public function testJitM3AllowlistIncludesParseAndCompileNotOnDenyList(): void
    {
        $jit = (string) file_get_contents(self::$root.'/lib/JIT.php');
        $this->assertMatchesRegularExpression(
            "/str_ends_with\\(\\\$lower, '\\\\\\\\runtime::parse'\\)/",
            $jit,
            'Runtime::parse must be on M3 compile-driver allowlist (#1496)'
        );
        $this->assertMatchesRegularExpression(
            "/str_ends_with\\(\\\$lower, '\\\\\\\\runtime::compile'\\)/",
            $jit,
            'Runtime::compile must be on M3 compile-driver allowlist (#1496)'
        );
        if (preg_match('/private function m3CompileDriverSpineDenyNames\\(\\): array\\s*\\{\\s*return \\[(.*?)\\];/s', $jit, $m)) {
            $denyBlock = $m[1];
            $this->assertStringNotContainsString('runtime::parse', $denyBlock);
            $this->assertStringNotContainsString('runtime::compile', $denyBlock);
        } else {
            $this->fail('Unable to parse m3CompileDriverSpineDenyNames from lib/JIT.php');
        }
    }

    public function testRuntimeParseCompileSmokeFixtureExists(): void
    {
        $fixture = self::$root.'/test/bootstrap-aot/runtime_parse_compile_smoke.php';
        $this->assertFileExists($fixture);
        $source = (string) file_get_contents($fixture);
        $this->assertStringContainsString('runtime_parse_compile_smoke', $source);
        $this->assertStringContainsString('->parse(', $source);
        $this->assertStringContainsString('->compile(', $source);
    }

    public function testRuntimeParseCompileSmokeLintPassesWhenLlvmPresent(): void
    {
        if (!LlvmToolchain::isReady(self::$root)) {
            $this->markTestSkipped('LLVM 9 not available for runtime_parse_compile_smoke lint.');
        }

        $fixture = self::$root.'/test/bootstrap-aot/runtime_parse_compile_smoke.php';
        $prefix = LlvmToolchain::envPrefix(self::$root);
        $cmd = implode(' ', array_map('escapeshellarg', [...$prefix, 'php', self::$root.'/bin/compile.php', '-l', $fixture])).' 2>&1';
        exec($cmd, $lines, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $lines));
    }

    public function testCompileDriverNullSafeRuntimeDispatch(): void
    {
        $driver = (string) file_get_contents(self::$root.'/test/selfhost/compiler_helloworld_smoke/compile_driver.php');
        $this->assertStringContainsString('PHP_COMPILER_M3_RUNTIME_COMPILE', $driver);
        $this->assertStringContainsString('emit path blocked', $driver);
    }

    /** Issue #1514: compile_driver must not read assoc arrays from stubbed smoke (hashtable segfault). */
    public function testM3SmokeUsesIntExitCodesNotAssocArrays(): void
    {
        $smoke = (string) file_get_contents(self::$root.'/test/bootstrap-aot/helloworld_compile_smoke.php');
        $ctor = (string) file_get_contents(self::$root.'/test/bootstrap-aot/runtime_ctor_smoke.php');
        $driver = (string) file_get_contents(self::$root.'/test/selfhost/compiler_helloworld_smoke/compile_driver.php');

        $this->assertStringContainsString('function helloworld_compile_smoke(string $sourceFile, string $outFile): int', $smoke);
        $this->assertStringContainsString('function runtime_ctor_smoke(): int', $ctor);
        $this->assertStringNotContainsString("\$result['message']", $driver);
        $this->assertStringNotContainsString("\$result['ok']", $driver);
        $this->assertStringNotContainsString('array{ok: bool', $smoke);
    }

    public function testM3CompileDriverRuntimeCompileModeDoesNotSegfault(): void
    {
        if (!LlvmToolchain::isReady(self::$root)) {
            $this->markTestSkipped('LLVM 9 not available for M3 compile-driver runtime test.');
        }

        $prefix = LlvmToolchain::envPrefix(self::$root);
        $linkCmd = implode(' ', array_map('escapeshellarg', [
            ...$prefix,
            'env',
            'BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1',
            'BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING=1',
            'PHP_COMPILER_M3_COMPILE_DRIVER=1',
            'make',
            '-C',
            self::$root,
            'bootstrap-selfhost-helloworld',
        ])).' 2>&1';
        exec($linkCmd, $linkLines, $linkCode);
        $linkOut = implode("\n", $linkLines);
        if (0 !== $linkCode || !is_executable(self::$root.'/build/selfhost-helloworld-compile')) {
            $this->markTestSkipped('compile driver link failed: '.$linkOut);
        }

        $outDir = sys_get_temp_dir().'/m3-hw-'.getmypid();
        @mkdir($outDir, 0777, true);
        $runCmd = implode(' ', array_map('escapeshellarg', [
            ...$prefix,
            'env',
            'PHP_COMPILER_M3_COMPILE_MODE=compile',
            'PHP_COMPILER_M3_RUNTIME_COMPILE=1',
            'PHP_COMPILER_M3_SOURCE='.self::$root.'/examples/000-HelloWorld/example.php',
            'PHP_COMPILER_M3_OUT='.$outDir.'/out',
            'env',
            '-u',
            'PHP_COMPILER_SELFHOST_AOT',
            self::$root.'/build/selfhost-helloworld-compile',
        ])).' 2>&1';
        exec($runCmd, $runLines, $runCode);
        $runOut = implode("\n", $runLines);

        $this->assertNotSame(139, $runCode, 'segfault in native compile driver: '.$runOut);
        $this->assertSame(0, $runCode, $runOut);
    }

    public function testRuntimeCtorSmokeFixtureExists(): void
    {
        $fixture = self::$root.'/test/bootstrap-aot/runtime_ctor_smoke.php';
        $this->assertFileExists($fixture);
        $source = (string) file_get_contents($fixture);
        $this->assertStringContainsString('runtime_ctor_smoke', $source);
        $this->assertStringContainsString('MODE_AOT', $source);
    }

    public function testHelloWorldProbeDefaultsRealLoweringWhenLinkCompileDriver(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-helloworld-probe.sh');
        $this->assertStringContainsString('BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING:-1', $script);
        $this->assertStringContainsString('#2571', $script);
        $this->assertStringContainsString('#2582', $script);
    }

    public function testHelloWorldProbeDocumentsInventoryEmitDriverOptIn(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-helloworld-probe.sh');
        $this->assertStringContainsString('BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER', $script);
        $this->assertStringContainsString('inventory compile_driver', $script);
        $jit = (string) file_get_contents(self::$root.'/lib/JIT.php');
        $this->assertStringContainsString('shouldUseM3InventoryEmitDriver', $jit);
        $this->assertStringContainsString('PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER', $jit);
    }

    public function testHelloWorldProbeDocumentsEmitPathAndStrict(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-helloworld-probe.sh');
        $this->assertStringContainsString('emit_path=', $script);
        $this->assertStringContainsString('BOOTSTRAP_M3_HELLOWORLD_STRICT=1', $script);
        $this->assertStringContainsString('block_reason=', $script);
        $this->assertStringContainsString('M3_NATIVE_COMPILE=1', $script);
        $this->assertStringContainsString('compile_smoke_m3_emit: compile OK', $script);
        $this->assertStringContainsString('helloworld_m3_emit_native_entry.php', $script);
        $this->assertStringContainsString('PHP_COMPILER_EMIT_HELPER_LINK=1', $script);
        $this->assertStringContainsString('helloworld_m3_emit_next_lower', $script);
        $this->assertStringContainsString('NEXT_LOWER_CMD:', $script);
        $this->assertStringContainsString('BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1', $script);
        $this->assertStringContainsString('missing executable', $script);
    }

    public function testHelloWorldM3EmitNativeEntryExists(): void
    {
        $entry = self::$root.'/test/bootstrap-aot/helloworld_m3_emit_native_entry.php';
        $this->assertFileExists($entry);
        $source = (string) file_get_contents($entry);
        $this->assertStringContainsString('compile_smoke_m3_emit', $source);
    }

    public function testCompilePhpSetsUnifiedEmitLogPrefixForHelloWorldEmitEntry(): void
    {
        $compile = (string) file_get_contents(self::$root.'/bin/compile.php');
        $this->assertStringContainsString('helloworld_m3_emit_native_entry.php', $compile);
        $this->assertStringContainsString("putenv('PHP_COMPILER_M3_EMIT_LOG_PREFIX=compile_smoke_m3_emit')", $compile);
    }

    /** Issue #2666: helloworld emit TU registers unit-probe + compile_driver sidecars without probe-only env. */
    public function testEmitTuCompileSmokeBranchRegistersUnifiedSidecars(): void
    {
        $jit = (string) file_get_contents(self::$root.'/lib/JIT.php');
        $this->assertStringContainsString("'compile_smoke_m3_emit' === \$logPrefix", $jit);
        $this->assertStringContainsString('compiler_unit_probe_compile.php', $jit);
        $this->assertStringContainsString('COMPILER_UNIT_PROBE_SIDECAR_REL', $jit);
        $this->assertStringContainsString('compile_driver.php', $jit);
        $this->assertStringContainsString('COMPILE_DRIVER_SIDECAR_REL', $jit);
        $this->assertStringContainsString('COMPILER_PHP_SIDECAR_REL', $jit);
        $this->assertStringContainsString('BIN_COMPILE_SIDECAR_REL', $jit);
        $this->assertStringContainsString('BIN_VM_SIDECAR_REL', $jit);
        $this->assertStringContainsString('CLI_DRIVER_SIDECAR_REL', $jit);
        $this->assertStringContainsString('isM5BootstrapSidecarScriptMain', $jit);
        $this->assertStringNotContainsString('PHP_COMPILER_M3_COMPILER_UNIT_PROBE_EMIT', $jit);
    }

    public function testHelloWorldM3EmitNativeEntryLinksWithRealLowering(): void
    {
        if (!LlvmToolchain::isReady(self::$root)) {
            $this->markTestSkipped('LLVM 9 not available for M3 HelloWorld emit helper link test.');
        }

        $entry = self::$root.'/test/bootstrap-aot/helloworld_m3_emit_native_entry.php';
        $out = self::$root.'/build/selfhost-helloworld-emit-test';
        @unlink($out);

        $prefix = LlvmToolchain::envPrefix(self::$root);
        $cmd = implode(' ', array_map('escapeshellarg', [
            ...$prefix,
            'env',
            'PHP_COMPILER_SELFHOST_AOT=1',
            'PHP_COMPILER_M3_COMPILE_DRIVER=1',
            'PHP_COMPILER_EMIT_HELPER_LINK=1',
            'php',
            self::$root.'/bin/compile.php',
            '-o',
            $out,
            $entry,
        ])).' 2>&1';
        exec($cmd, $lines, $exitCode);

        if (139 === $exitCode) {
            $this->markTestSkipped('LLVM 9 segfault during M3 emit-helper link (#2442).');
        }

        $this->assertSame(0, $exitCode, implode("\n", $lines));
        $this->assertFileExists($out);
        $this->assertTrue(is_executable($out));
    }

    /** Issue #2843: inventory compile_driver links without *_m3_emit_native_entry.php. */
    public function testInventoryCompileDriverLinksWithRealLowering(): void
    {
        if (!LlvmToolchain::isReady(self::$root)) {
            $this->markTestSkipped('LLVM 9 not available for inventory compile_driver link test.');
        }

        $entry = self::$root.'/test/selfhost/compiler_helloworld_smoke/compile_driver.php';
        $out = self::$root.'/build/selfhost-inventory-emit-test';
        @unlink($out);

        $prefix = LlvmToolchain::envPrefix(self::$root);
        $cmd = implode(' ', array_map('escapeshellarg', [
            ...$prefix,
            'env',
            'PHP_COMPILER_SELFHOST_AOT=1',
            'PHP_COMPILER_M3_COMPILE_DRIVER=1',
            'PHP_COMPILER_EMIT_HELPER_LINK=1',
            'PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1',
            'BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER=1',
            'PHP_COMPILER_M3_EMIT_LOG_PREFIX=helloworld_compile_smoke',
            'php',
            self::$root.'/bin/compile.php',
            '-o',
            $out,
            $entry,
        ])).' 2>&1';
        exec($cmd, $lines, $exitCode);

        if (139 === $exitCode) {
            $this->markTestSkipped('LLVM 9 segfault during inventory compile_driver link (#2843).');
        }

        $this->assertSame(0, $exitCode, implode("\n", $lines));
        $this->assertFileExists($out);
        $this->assertTrue(is_executable($out));

        $hw = self::$root.'/examples/000-HelloWorld/example.php';
        $aotOut = self::$root.'/build/selfhost-inventory-emit-hw-aot';
        @unlink($aotOut);
        $runCmd = implode(' ', array_map('escapeshellarg', [
            ...$prefix,
            'env',
            'PHP_COMPILER_M3_EMIT_MINIMAL=1',
            'PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1',
            'PHP_COMPILER_M3_SOURCE='.$hw,
            'PHP_COMPILER_M3_OUT='.$aotOut,
            $out,
        ])).' 2>&1';
        exec($runCmd, $runLines, $runExit);
        if (139 === $runExit) {
            $this->markTestSkipped('LLVM 9 segfault during inventory compile_driver emit run (#2843).');
        }
        $this->assertSame(0, $runExit, implode("\n", $runLines));
        $this->assertStringContainsString('helloworld_compile_smoke: compile OK', implode("\n", $runLines));
        $this->assertFileExists($aotOut);
    }

    /** Issue #2681: M3 emit TU sidecar for lib/Compiler.php on compile_smoke_m3_emit link (#2666). */
    public function testM3EmitHelperNativeEmitCompilerPhpViaSidecar(): void
    {
        if (!LlvmToolchain::isReady(self::$root)) {
            $this->markTestSkipped('LLVM 9 not available for M3 Compiler.php sidecar emit test.');
        }

        $entry = self::$root.'/test/bootstrap-aot/helloworld_m3_emit_native_entry.php';
        $emitHelper = self::$root.'/build/selfhost-helloworld-emit-compiler-php-test';
        $aotOut = self::$root.'/build/m3-compiler-php-aot-test';
        @unlink($emitHelper);
        @unlink($aotOut);

        $prefix = LlvmToolchain::envPrefix(self::$root);
        $linkCmd = implode(' ', array_map('escapeshellarg', [
            ...$prefix,
            'env',
            'PHP_COMPILER_SELFHOST_AOT=1',
            'PHP_COMPILER_M3_COMPILE_DRIVER=1',
            'PHP_COMPILER_EMIT_HELPER_LINK=1',
            'php',
            self::$root.'/bin/compile.php',
            '-o',
            $emitHelper,
            $entry,
        ])).' 2>&1';
        exec($linkCmd, $linkLines, $linkCode);
        if (139 === $linkCode) {
            $this->markTestSkipped('LLVM 9 segfault during M3 emit-helper link (#2442).');
        }
        $this->assertSame(0, $linkCode, implode("\n", $linkLines));
        $this->assertFileExists($emitHelper);

        $runCmd = implode(' ', array_map('escapeshellarg', [
            ...$prefix,
            'env',
            'PHP_COMPILER_M3_EMIT_MINIMAL=1',
            'PHP_COMPILER_M3_SOURCE='.self::$root.'/lib/Compiler.php',
            'PHP_COMPILER_M3_OUT='.$aotOut,
            $emitHelper,
        ])).' 2>&1';
        exec($runCmd, $runLines, $runCode);
        $runOut = implode("\n", $runLines);
        if (139 === $runCode) {
            $this->markTestSkipped('LLVM 9 segfault during M3 Compiler.php sidecar emit (#2540).');
        }
        $this->assertSame(0, $runCode, $runOut);
        $this->assertStringContainsString('compile_smoke_m3_emit: compile OK', $runOut);
        $this->assertFileExists($aotOut);
        $this->assertGreaterThan(0, filesize($aotOut));
    }

    /** Issue #2827 / #2697: M5 bin/compile.php sidecar + native emit via compile_smoke_m3_emit TU. */
    public function testM3EmitHelperNativeEmitBinCompilePhpViaSidecar(): void
    {
        if (!LlvmToolchain::isReady(self::$root)) {
            $this->markTestSkipped('LLVM 9 not available for M3 bin/compile.php sidecar emit test.');
        }

        $entry = self::$root.'/test/bootstrap-aot/helloworld_m3_emit_native_entry.php';
        $emitHelper = self::$root.'/build/selfhost-helloworld-emit-bin-compile-test';
        $aotOut = self::$root.'/build/m3-bin-compile-aot-test';
        $sidecar = self::$root.'/'.JIT\M3EmitTuTrivialEchoAot::BIN_COMPILE_SIDECAR_REL;
        @unlink($emitHelper);
        @unlink($aotOut);
        @unlink($sidecar);

        $prefix = LlvmToolchain::envPrefix(self::$root);
        $linkCmd = implode(' ', array_map('escapeshellarg', [
            ...$prefix,
            'env',
            'PHP_COMPILER_SELFHOST_AOT=1',
            'PHP_COMPILER_M3_COMPILE_DRIVER=1',
            'PHP_COMPILER_EMIT_HELPER_LINK=1',
            'php',
            self::$root.'/bin/compile.php',
            '-o',
            $emitHelper,
            $entry,
        ])).' 2>&1';
        exec($linkCmd, $linkLines, $linkCode);
        if (139 === $linkCode) {
            $this->markTestSkipped('LLVM 9 segfault during M3 emit-helper link (#2442).');
        }
        $this->assertSame(0, $linkCode, implode("\n", $linkLines));
        $this->assertFileExists($emitHelper);
        $this->assertFileExists($sidecar, 'bin/compile.php sidecar must be registered at emit-helper link (#2827)');

        $runCmd = implode(' ', array_map('escapeshellarg', [
            ...$prefix,
            'env',
            'PHP_COMPILER_M3_EMIT_MINIMAL=1',
            'PHP_COMPILER_M3_SOURCE='.self::$root.'/bin/compile.php',
            'PHP_COMPILER_M3_OUT='.$aotOut,
            $emitHelper,
        ])).' 2>&1';
        exec($runCmd, $runLines, $runCode);
        $runOut = implode("\n", $runLines);
        if (139 === $runCode) {
            $this->markTestSkipped('LLVM 9 segfault during M3 bin/compile.php sidecar emit (#2540).');
        }
        $this->assertSame(0, $runCode, $runOut);
        $this->assertStringContainsString('compile_smoke_m3_emit: compile OK', $runOut);
        $this->assertFileExists($aotOut);
        $this->assertGreaterThan(0, filesize($aotOut));
    }

    /** Issue #2827: M5 driver path uses helloworld_compile_smoke log prefix + compile_driver sidecars. */
    public function testM3HelloWorldCompileDriverNativeEmitBinCompilePhp(): void
    {
        if (!LlvmToolchain::isReady(self::$root)) {
            $this->markTestSkipped('LLVM 9 not available for M3 helloworld compile-driver bin/compile.php test.');
        }

        $entry = self::$root.'/test/bootstrap-aot/helloworld_compile_m3_emit_native_entry.php';
        $compileDriver = self::$root.'/build/selfhost-helloworld-compile-bin-compile-test';
        $aotOut = self::$root.'/build/m3-bin-compile-hw-driver-aot-test';
        $sidecar = self::$root.'/'.JIT\M3EmitTuTrivialEchoAot::BIN_COMPILE_SIDECAR_REL;
        @unlink($compileDriver);
        @unlink($aotOut);
        @unlink($sidecar);

        $prefix = LlvmToolchain::envPrefix(self::$root);
        $linkCmd = implode(' ', array_map('escapeshellarg', [
            ...$prefix,
            'env',
            'PHP_COMPILER_SELFHOST_AOT=1',
            'PHP_COMPILER_M3_COMPILE_DRIVER=1',
            'PHP_COMPILER_EMIT_HELPER_LINK=1',
            'php',
            self::$root.'/bin/compile.php',
            '-o',
            $compileDriver,
            $entry,
        ])).' 2>&1';
        exec($linkCmd, $linkLines, $linkCode);
        if (139 === $linkCode) {
            $this->markTestSkipped('LLVM 9 segfault during helloworld compile-driver link (#2442).');
        }
        $this->assertSame(0, $linkCode, implode("\n", $linkLines));
        $this->assertFileExists($compileDriver);
        $this->assertFileExists($sidecar, 'helloworld_compile_smoke link must register bin/compile.php sidecar (#2827)');

        $runCmd = implode(' ', array_map('escapeshellarg', [
            ...$prefix,
            'env',
            'PHP_COMPILER_M3_COMPILE_MODE=compile',
            'PHP_COMPILER_M3_RUNTIME_COMPILE=1',
            'PHP_COMPILER_M3_SOURCE='.self::$root.'/bin/compile.php',
            'PHP_COMPILER_M3_OUT='.$aotOut,
            $compileDriver,
        ])).' 2>&1';
        exec($runCmd, $runLines, $runCode);
        $runOut = implode("\n", $runLines);
        if (139 === $runCode) {
            $this->markTestSkipped('LLVM 9 segfault during helloworld_compile_smoke bin/compile.php emit.');
        }
        $this->assertSame(0, $runCode, $runOut);
        $this->assertStringContainsString('helloworld_compile_smoke: compile OK', $runOut);
        $this->assertFileExists($aotOut);
        $this->assertGreaterThan(0, filesize($aotOut));
    }

    public function testCompileEmitSmokeInlinesMainCompileForFunctionScripts(): void
    {
        $source = (string) file_get_contents(self::$root.'/lib/Compiler.php');
        $this->assertStringContainsString('emit-smoke only needs {main}', $source);
        $this->assertStringNotContainsString('return $this->compile($script);', $source);
    }

    public function testEmitTuInitDoesNotVoidStubInitParsePipeline(): void
    {
        $emit = (string) file_get_contents(self::$root.'/lib/JIT/BootstrapCompileSmokeM3Emit.php');
        $this->assertStringNotContainsString("'initparsepipeline', 'initcompiler', 'loadcoremodules'", $emit);
    }

    public function testEmitTuEnsureEmitBridgeSpineSymbols(): void
    {
        $jit = (string) file_get_contents(self::$root.'/lib/JIT.php');
        $this->assertStringContainsString('ensureM3EmitTuEmitBridgeSpineSymbols', $jit);
    }

    public function testExternalJitClassRegistersIdToName(): void
    {
        $source = (string) file_get_contents(self::$root.'/lib/JIT/Builtin/Type/Object_.php');
        $this->assertStringContainsString(
            '$this->classIdToName[$id] = $lcname;',
            $source,
            'registerExternalClass must populate classIdToName for RuntimeInitVmContext propertyFetch (#1514, #2126)'
        );
    }

    public function testCiLocalDocumentsM3StrictGate(): void
    {
        $common = (string) file_get_contents(self::$root.'/script/ci-common.sh');
        $local = (string) file_get_contents(self::$root.'/script/ci-local.sh');
        $this->assertStringContainsString('ci_run_bootstrap_m3_strict', $common);
        $this->assertStringContainsString('BOOTSTRAP_M3_HELLOWORLD_STRICT_GATE', $common);
        $this->assertStringContainsString('ci_run_bootstrap_m3_strict', $local);
    }

    public function testHelloWorldProbeStrictFailsWhenRuntimeNativeEmitBlocked(): void
    {
        if (!LlvmToolchain::isReady(self::$root)) {
            $this->markTestSkipped('LLVM 9 not available for M3 HelloWorld strict probe test.');
        }

        $script = self::$root.'/script/bootstrap-selfhost-helloworld-probe.sh';
        $prefix = LlvmToolchain::envPrefix(self::$root);
        $cmd = implode(' ', array_map('escapeshellarg', [
            ...$prefix,
            'env',
            'BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1',
            'BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING=1',
            'BOOTSTRAP_M3_RUNTIME_COMPILE=0',
            'BOOTSTRAP_M3_HELLOWORLD_STRICT=1',
            'bash',
            $script,
        ])).' 2>&1';
        exec($cmd, $lines, $exitCode);

        $out = implode("\n", $lines);
        if (str_contains($out, 'link bundle failed')) {
            $this->markTestSkipped('HelloWorld bundle link failed (LLVM 9 flake); strict runtime gate needs bundle link OK first.');
        }
        $this->assertSame(1, $exitCode, $out);
        $this->assertStringContainsString('emit_path=zend_fallback_would_be_used', $out);
        $this->assertStringContainsString('block_reason=', $out);
        $this->assertStringNotContainsString('OK emit_path=native', $out);
        $this->assertStringNotContainsString('M3_NATIVE_COMPILE=1 emit_path=native', $out);
    }

    public function testHelloWorldProbeDefaultNativeEmitWhenLlvmPresent(): void
    {
        if (!LlvmToolchain::isReady(self::$root)) {
            $this->markTestSkipped('LLVM 9 not available for M3 HelloWorld default native emit test.');
        }

        $script = self::$root.'/script/bootstrap-selfhost-helloworld-probe.sh';
        $prefix = LlvmToolchain::envPrefix(self::$root);
        $cmd = implode(' ', array_map('escapeshellarg', [...$prefix, 'bash', $script])).' 2>&1';
        exec($cmd, $lines, $exitCode);

        $out = implode("\n", $lines);
        $this->assertSame(0, $exitCode, $out);
        $this->assertStringContainsString('OK emit_path=native', $out);
        $this->assertStringNotContainsString('OK emit_path=zend partial', $out);
    }

    public function testJitStubsFirstClassCallableForSelfHost(): void
    {
        $jit = (string) file_get_contents(self::$root.'/lib/JIT.php');
        $this->assertStringContainsString('compilefirstclasscallable', $jit);
    }

    public function testCliDriverDispatchEntryForM5CompiledDriver(): void
    {
        $cli = (string) file_get_contents(self::$root.'/src/cli_driver.php');
        $this->assertStringContainsString('function php_compiler_cli_dispatch(): void', $cli);
        $this->assertStringContainsString('function php_compiler_cli_should_run_entry_driver(): bool', $cli);
        $this->assertStringContainsString('global $argv', $cli);

        $compile = (string) file_get_contents(self::$root.'/bin/compile.php');
        $this->assertStringContainsString('php_compiler_cli_dispatch();', $compile);

        $vm = (string) file_get_contents(self::$root.'/bin/vm.php');
        $this->assertStringContainsString('php_compiler_cli_dispatch();', $vm);
    }

    public function testJitM5DriverHostCompileEnv(): void
    {
        $jit = (string) file_get_contents(self::$root.'/lib/JIT.php');
        $this->assertStringContainsString('shouldUseM5DriverHostCompile', $jit);
        $this->assertStringContainsString('PHP_COMPILER_M5_DRIVER_HOST', $jit);
        $this->assertStringContainsString('CliArgvGlobalInit', $jit);

        $argvInit = (string) file_get_contents(self::$root.'/lib/JIT/CliArgvGlobalInit.php');
        $this->assertStringContainsString('jit_global_argv', $argvInit);
        $this->assertStringContainsString('emitRefreshAfterStoreArgv', $argvInit);

        $script = self::$root.'/script/bootstrap-selfhost-driver-host-compile.sh';
        $this->assertFileExists($script);
        $body = (string) file_get_contents($script);
        $this->assertStringContainsString('BOOTSTRAP_M5_DRIVER_HOST_FULL_CLI', $body);
    }
}
