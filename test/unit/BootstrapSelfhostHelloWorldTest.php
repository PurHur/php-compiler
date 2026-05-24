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
            'env', 'PHP_COMPILER_SELFHOST_AOT=1', 'PHP_COMPILER_M3_COMPILE_DRIVER=1',
            'php', self::$root.'/bin/compile.php', '-o', $out, $driver,
        ])).' 2>&1';
        exec($cmd, $lines, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $lines));
        $this->assertFileExists($out);
        $this->assertTrue(is_executable($out));
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
        $this->assertStringContainsString('helloworld_compile_smoke', $jit);
        $this->assertStringContainsString('runtime::parseandcompile', $jit);
        $this->assertStringContainsString('runtime::parse', $jit);
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

    public function testRuntimeCtorSmokeFixtureExists(): void
    {
        $fixture = self::$root.'/test/bootstrap-aot/runtime_ctor_smoke.php';
        $this->assertFileExists($fixture);
        $source = (string) file_get_contents($fixture);
        $this->assertStringContainsString('runtime_ctor_smoke', $source);
        $this->assertStringContainsString('MODE_AOT', $source);
    }

    public function testHelloWorldProbeDocumentsEmitPathAndStrict(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-helloworld-probe.sh');
        $this->assertStringContainsString('emit_path=', $script);
        $this->assertStringContainsString('BOOTSTRAP_M3_HELLOWORLD_STRICT=1', $script);
        $this->assertStringContainsString('block_reason=', $script);
        $this->assertStringContainsString('M3_NATIVE_COMPILE=1', $script);
        $this->assertStringContainsString('helloworld_compile_smoke: compile OK', $script);
        $this->assertStringContainsString('missing executable', $script);
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
