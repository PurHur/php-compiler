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

    public function testHelloWorldCompileDriverHasModeDispatch(): void
    {
        $driver = (string) file_get_contents(self::$root.'/test/selfhost/compiler_helloworld_smoke/compile_driver.php');
        $this->assertStringContainsString('.m3-helloworld-mode', $driver);
        $this->assertStringContainsString('helloworld_compile_smoke', $driver);
        $this->assertStringContainsString('compiler_smoke.php', $driver);
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
        $this->assertStringContainsString('includehelper', $jit);
    }

    public function testCompileDriverNullSafeRuntimeDispatch(): void
    {
        $driver = (string) file_get_contents(self::$root.'/test/selfhost/compiler_helloworld_smoke/compile_driver.php');
        $this->assertStringContainsString('PHP_COMPILER_M3_RUNTIME_COMPILE', $driver);
        $this->assertStringContainsString('native runtime blocked', $driver);
    }

    public function testJitStubsFirstClassCallableForSelfHost(): void
    {
        $jit = (string) file_get_contents(self::$root.'/lib/JIT.php');
        $this->assertStringContainsString('compilefirstclasscallable', $jit);
    }
}
