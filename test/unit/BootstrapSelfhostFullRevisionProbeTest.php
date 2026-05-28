<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

final class BootstrapSelfhostFullRevisionProbeTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testFullRevisionProbeScriptUsesArgvOnly(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-full-revision-probe.sh');
        $this->assertStringContainsString('bootstrap-selfhost-full-revision-probe:', $script);
        $this->assertStringContainsString('bin/compile.php', $script);
        $this->assertStringContainsString('bin-compile-aot', $script);
        $this->assertStringContainsString('compiler_smoke_standalone.php', $script);
        $this->assertStringContainsString('env -u PHP_COMPILER_M3_SOURCE -u PHP_COMPILER_M3_OUT', $script);
        $this->assertStringContainsString('emit_path=native', $script);
        $this->assertStringContainsString('#2880', $script);
    }

    public function testMakefileExposesFullRevisionProbeTarget(): void
    {
        $makefile = (string) file_get_contents(self::$root.'/Makefile');
        $this->assertStringContainsString('bootstrap-selfhost-full-revision-probe:', $makefile);
        $this->assertStringContainsString('./script/bootstrap-selfhost-full-revision-probe.sh', $makefile);
    }

    public function testHelloworldCompileBinSyncsBinCompileSidecar(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-helloworld-compile-bin.sh');
        $this->assertStringContainsString('.m3_bin_compile_aot_blob', $script);
        $this->assertStringContainsString('#2880', $script);
    }

    public function testJitRegistersM5DriverHostForBinCompileSidecar(): void
    {
        $jit = (string) file_get_contents(self::$root.'/lib/JIT.php');
        $this->assertStringContainsString("'/bin/compile.php'))", $jit);
        $this->assertStringContainsString('PHP_COMPILER_M5_DRIVER_HOST', $jit);
        $this->assertStringContainsString('#2880', $jit);
    }
}
