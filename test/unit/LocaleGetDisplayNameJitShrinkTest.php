<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\intl\LocaleGetDisplayNameJitHelper;
use PHPUnit\Framework\TestCase;

/** locale_get_display_name() JIT routes through JitLocaleGetDisplayName (#32120). */
final class LocaleGetDisplayNameJitShrinkTest extends TestCase
{
    public function testBuiltinRoutesThroughJitLocaleGetDisplayNameNotRefuse(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/intl/locale_get_display_name.php');
        $this->assertStringContainsString('JitLocaleGetDisplayName::getDisplayName', $builtin);
        $this->assertStringNotContainsString('not implemented; use VM', $builtin);

        $lowering = (string) file_get_contents(__DIR__.'/../../ext/intl/JitLocaleGetDisplayName.php');
        $this->assertStringContainsString('LocaleGetDisplayNameRuntime::invoke', $lowering);
        $this->assertStringContainsString('VmLocale::getDisplayName', $lowering);

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/LocaleGetDisplayNameRuntime.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $runtime);
        $this->assertStringContainsString('getDisplayNameArgv', $runtime);
        $this->assertStringContainsString('__phpc_jit_locale_get_display_name', $runtime);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $runtime);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $runtime);

        $method = (string) file_get_contents(__DIR__.'/../../ext/intl/LocaleGetDisplayName.php');
        $this->assertStringContainsString('JitLocaleGetDisplayName::getDisplayName', $method);

        $ctx = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("functionProxies['locale::getdisplayname']", $ctx);
    }

    public function testJitHelperDelegatesToVmLocale(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/intl/LocaleGetDisplayNameJitHelper.php');
        $this->assertStringContainsString('VmLocale::getDisplayName', $source);
        $this->assertStringContainsString('getDisplayNameArgv', $source);
    }

    public function testSpineBundleIncludesLocaleGetDisplayNameJit(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitLocaleGetDisplayName.php', $spine);
        $this->assertStringContainsString('LocaleGetDisplayNameJitHelper.php', $spine);
        $this->assertStringContainsString('LocaleGetDisplayNameRuntime.php', $spine);
    }

    public function testJitHelperGetDisplayNameArgvMatchesPhpSrc(): void
    {
        $this->assertSame('German (Germany)', LocaleGetDisplayNameJitHelper::getDisplayNameArgv('de_DE', 'en', 1));
    }
}
