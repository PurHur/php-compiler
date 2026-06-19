<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** SilenceRuntime must route @ through ErrorSilenceJitHelper PHP, not LLVM silence globals (#9197). */
final class SilenceRuntimeShrinkTest extends TestCase
{
    public function testSilenceRuntimeUsesErrorSilenceJitHelperNotLlvmGlobals(): void
    {
        $iniSource = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/IniRuntime.php');
        $this->assertStringContainsString('SilenceRuntime::ensureLinked', $iniSource);
        $this->assertStringNotContainsString('implementBeginSilence', $iniSource);
        $this->assertStringNotContainsString('implementEndSilence', $iniSource);
        $this->assertStringNotContainsString("G_SILENCE_DEPTH = 'phpc_ini_silence_depth'", $iniSource);
        $this->assertStringNotContainsString("G_ERROR_REPORTING = 'phpc_ini_error_reporting'", $iniSource);

        $silenceSource = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SilenceRuntime.php');
        $this->assertStringContainsString('ErrorSilenceJitHelper', $silenceSource);
    }

    public function testErrorSilenceHelperUsesSilenceRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/ErrorSilenceHelper.php');
        $this->assertStringContainsString('SilenceRuntime', $source);
        $this->assertStringNotContainsString('IniRuntime', $source);
    }
}
