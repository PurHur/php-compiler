<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @group aot-lint */
final class BootstrapCompilerUnitProbeTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testCompilerUnitProbeCompileSmokeParsesFixture(): void
    {
        require_once self::$root.'/test/bootstrap-aot/compiler_unit_probe_compile.php';

        $code = (string) file_get_contents(self::$root.'/test/bootstrap-aot/compiler_unit_probe_standalone.php');
        $this->assertTrue(
            \PHPCompiler\BootstrapAot\compiler_unit_probe_compile_smoke($code, self::$root.'/test/bootstrap-aot/compiler_unit_probe_standalone.php')
        );
    }

    /**
     * @group llvm
     */
    public function testNativeCompilerUnitProbeStrictEmitWhenLlvmPresent(): void
    {
        if (!LlvmToolchain::isReady(self::$root)) {
            $this->markTestSkipped('LLVM 9 not available for compiler unit probe strict emit test.');
        }

        $script = self::$root.'/script/bootstrap-selfhost-compiler-unit-probe.sh';
        $prefix = LlvmToolchain::envPrefix(self::$root);
        $cmd = implode(' ', array_map('escapeshellarg', [
            ...$prefix,
            'env',
            'BOOTSTRAP_M3_COMPILER_UNIT_PROBE_STRICT=1',
            'bash',
            $script,
        ])).' 2>&1';
        exec($cmd, $lines, $exitCode);

        $out = implode("\n", $lines);
        $this->assertSame(0, $exitCode, $out);
        $this->assertStringContainsString('emit_path=native', $out);
        $this->assertStringContainsString('compiler unit probe', $out);
    }

    public function testMakefileDefinesCompilerUnitProbeStrictTarget(): void
    {
        $makefile = (string) file_get_contents(self::$root.'/Makefile');
        $this->assertStringContainsString('bootstrap-selfhost-compiler-unit-probe-strict:', $makefile);
        $this->assertStringContainsString('BOOTSTRAP_M3_COMPILER_UNIT_PROBE_STRICT=1', $makefile);
    }

    public function testEmitNativeEntryExists(): void
    {
        $entry = self::$root.'/test/bootstrap-aot/compiler_unit_probe_m3_emit_native_entry.php';
        $this->assertFileExists($entry);
        $contents = (string) file_get_contents($entry);
        $this->assertStringContainsString('compile_smoke_m3_emit', $contents);
        $this->assertStringContainsString('lib/Compiler.php', $contents);
    }
}
