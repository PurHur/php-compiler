<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\IniJitHelper;
use PHPUnit\Framework\TestCase;

/** ini_get/ini_set JIT: always IniJitHelper NestedJIT — no thin false/nop stubs (#9249, #21200). */
final class IniRuntimeShrinkTest extends TestCase
{
    public function testIniJitHelperDelegatesToVmIniSemantics(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/IniJitHelper.php');
        $this->assertStringContainsString('VmIni::', $source);
        $this->assertStringContainsString('ErrorSilenceJitHelper', $source);
        // Cross-class array const fetch breaks inventory argv AOT compile (#12178 regression).
        $this->assertStringContainsString('EMPTY_STRING_INI_KEYS', $source);
        $this->assertStringNotContainsString('VmIni::EMPTY_STRING_INI_KEYS', $source);
    }

    public function testIniJitHelperEmptyStringIniKeysParity(): void
    {
        $this->assertSame('', IniJitHelper::iniGet('auto_prepend_file'));
        $this->assertSame('', IniJitHelper::iniGet('error_log'));
        $this->assertSame('', IniJitHelper::iniGet('disable_functions'));
        $this->assertSame('', IniJitHelper::iniGet('open_basedir'));
        $this->assertSame('.user.ini', IniJitHelper::iniGet('user_ini.filename'));
    }

    public function testIniRuntimeAlwaysUsesJitHelperBridge(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/IniRuntime.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('ini_get_bridge_entry', $source);
        $this->assertStringContainsString('VmActiveContextInitLlvm::requestThinStandaloneInit', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('ensureThinAotLinkStubs', $source);
        $this->assertStringNotContainsString('implementIniGetFalseStub', $source);
        $this->assertStringNotContainsString('implementIniSetFalseStub', $source);
        $this->assertStringNotContainsString('implementIniRestoreNopStub', $source);
        $this->assertStringNotContainsString('ini_get_thin_stub', $source);
        $this->assertStringNotContainsString('ini_set_thin_stub', $source);
        $this->assertStringNotContainsString('ini_restore_thin_stub', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('branchIfKey', $source);
        $this->assertStringNotContainsString("lookupFunction('strcasecmp')", $source);
        // Thin EG(exception_ignore_args) path for AOT NestedJIT SEGV (#27549) — not a false stub.
        $this->assertStringContainsString('phpc_ini_exception_ignore_args', $source);
        $this->assertStringContainsString('emitThinSetExceptionIgnoreArgs', $source);
        $this->assertStringContainsString('emitParseBoolIni', $source);
        $lineCount = \substr_count($source, "\n") + 1;
        $this->assertLessThanOrEqual(650, $lineCount);
        $this->assertGreaterThan(400, 1034 - $lineCount);
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

    public function testIniJitHelperDefaultCharsetRoundTrip(): void
    {
        $orig = IniJitHelper::iniGet('default_charset');
        $this->assertSame('UTF-8', $orig);
        $old = IniJitHelper::iniSet('default_charset', 'ISO-8859-1');
        $this->assertSame('UTF-8', $old);
        $this->assertSame('ISO-8859-1', IniJitHelper::iniGet('default_charset'));
        IniJitHelper::iniRestore('default_charset');
        $this->assertSame('UTF-8', IniJitHelper::iniGet('default_charset'));
    }

    public function testSpineBundleIncludesIniPhpJitPath(): void
    {
        $spine = (string) \file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('IniJitHelper.php', $spine);
        $this->assertStringContainsString('IniRuntime.php', $spine);
    }
}
