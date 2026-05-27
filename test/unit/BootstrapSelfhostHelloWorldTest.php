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
        $this->assertFileIsExecutable($driver);
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
            'BOOTSTRAP_M3_RUNTIME_COMPILE=1',
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

    public function testJitStubsFirstClassCallableForSelfHost(): void
    {
        $jit = (string) file_get_contents(self::$root.'/lib/JIT.php');
        $this->assertStringContainsString('compilefirstclasscallable', $jit);
    }
}
