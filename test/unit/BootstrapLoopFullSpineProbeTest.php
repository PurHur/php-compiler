<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @coversNothing */
final class BootstrapLoopFullSpineProbeTest extends TestCase
{
    public function testFullSpineProbeScriptAndMakefileTargetExist(): void
    {
        $root = dirname(__DIR__, 2);
        $script = $root.'/script/bootstrap-loop-full-spine-probe.sh';
        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script), $script.' must be executable');

        $makefile = (string) file_get_contents($root.'/Makefile');
        $this->assertStringContainsString('bootstrap-loop-full-spine-probe:', $makefile);
        $this->assertStringContainsString('./script/bootstrap-loop-full-spine-probe.sh', $makefile);
    }

    public function testFullSpineProbeScriptEnablesGen1CompileFullSpine(): void
    {
        $root = dirname(__DIR__, 2);
        $body = (string) file_get_contents($root.'/script/bootstrap-loop-full-spine-probe.sh');
        $this->assertStringContainsString('BOOTSTRAP_M4_GEN1_COMPILE_FULL_SPINE=1', $body);
        $this->assertStringContainsString('bootstrap-loop-probe.sh', $body);
    }

    public function testGen2RecompileMinimalScriptAndMakefileTargetExist(): void
    {
        $root = dirname(__DIR__, 2);
        $script = $root.'/script/bootstrap-loop-gen2-recompile-minimal.sh';
        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script), $script.' must be executable');

        $makefile = (string) file_get_contents($root.'/Makefile');
        $this->assertStringContainsString('bootstrap-loop-gen2-recompile-minimal:', $makefile);
        $spineScript = $root.'/script/bootstrap-loop-gen2-recompile-spine.sh';
        $body = (string) file_get_contents($spineScript);
        $this->assertStringContainsString('compiler_lib_spine_smoke', $body);
        $this->assertStringContainsString('build/bin-compile-aot', $body);
        $this->assertStringContainsString('-o "${GEN3}"', $body);
        $this->assertStringNotContainsString('PHP_COMPILER_M3_SOURCE="${SOURCE}"', $body);
    }
}
