<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * @group selfhost-m3-emit
 */
final class BootstrapM3EmitTuExecuteTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testEmitTuExecuteScriptExists(): void
    {
        $script = self::$root.'/script/bootstrap-m3-emit-tu-execute.sh';
        $this->assertFileExists($script);
        $source = (string) file_get_contents($script);
        $this->assertStringContainsString('runtime_m3_emit_native_entry.php', $source);
        $this->assertStringContainsString('runtime_compile_smoke_m3_emit: compile OK', $source);
        $this->assertStringContainsString('PHP_COMPILER_EMIT_HELPER_LINK=1', $source);
    }

    /**
     * @group llvm
     */
    public function testRuntimeEmitTuNativeLinkCompileAndRunWhenLlvmPresent(): void
    {
        if (!LlvmToolchain::isReady(self::$root)) {
            $this->markTestSkipped('LLVM 9 not available for M3 emit-TU execute test.');
        }

        $script = self::$root.'/script/bootstrap-m3-emit-tu-execute.sh';
        $prefix = LlvmToolchain::envPrefix(self::$root);
        $cmd = implode(' ', array_map('escapeshellarg', [...$prefix, 'bash', $script])).' 2>&1';
        exec($cmd, $lines, $exitCode);

        $out = implode("\n", $lines);
        $this->assertSame(0, $exitCode, $out);
        $this->assertStringContainsString('bootstrap-m3-emit-tu-execute: OK', $out);
        $this->assertStringContainsString('m3-emit-tu-aot stdout: 1', $out);
        $this->assertTrue(is_executable(self::$root.'/build/m3-emit-tu-phpunit-aot'));
    }
}
