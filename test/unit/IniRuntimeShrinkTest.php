<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\IniJitHelper;
use PHPUnit\Framework\TestCase;

/** ini_get/ini_set JIT routes through IniJitHelper PHP, not LLVM ini tables (#9249). */
final class IniRuntimeShrinkTest extends TestCase
{
    public function testIniJitHelperDelegatesToVmIniSemantics(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/IniJitHelper.php');
        $this->assertStringContainsString('VmIni::', $source);
        $this->assertStringContainsString('ErrorSilenceJitHelper', $source);
    }

    public function testIniRuntimeUsesJitHelperNotLlvmKeyWalk(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/IniRuntime.php');
        $this->assertStringContainsString('IniJitHelper', $source);
        $this->assertStringNotContainsString('branchIfKey', $source);
        $this->assertStringNotContainsString("lookupFunction('strcasecmp')", $source);
        $this->assertStringNotContainsString('emitParseBoolIni', $source);
        $lineCount = \substr_count($source, "\n") + 1;
        $this->assertLessThanOrEqual(360, $lineCount);
        $this->assertGreaterThan(600, 1034 - $lineCount);
    }

    public function testIniJitHelperMemoryLimitRoundTrip(): void
    {
        $orig = IniJitHelper::iniGet('memory_limit');
        $this->assertNotNull($orig);
        $old = IniJitHelper::iniSet('memory_limit', '128M');
        $this->assertSame($orig, $old);
        $this->assertSame('128M', IniJitHelper::iniGet('memory_limit'));
        IniJitHelper::iniRestore('memory_limit');
        $this->assertSame('-1', IniJitHelper::iniGet('memory_limit'));
    }

    public function testIniJitHelperUnknownKeyReturnsNull(): void
    {
        $this->assertNull(IniJitHelper::iniGet('bogus_ini_key'));
    }
}
