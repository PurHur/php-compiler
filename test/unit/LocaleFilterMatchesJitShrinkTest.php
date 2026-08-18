<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\intl\LocaleFilterMatchesJitHelper;
use PHPUnit\Framework\TestCase;

/** locale_filter_matches() JIT routes through JitLocaleFilterMatches (#32119). */
final class LocaleFilterMatchesJitShrinkTest extends TestCase
{
    public function testBuiltinRoutesThroughJitLocaleFilterMatchesNotRefuse(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/intl/locale_filter_matches.php');
        $this->assertStringContainsString('JitLocaleFilterMatches::filterMatches', $builtin);
        $this->assertStringNotContainsString('not implemented; use VM', $builtin);

        $lowering = (string) file_get_contents(__DIR__.'/../../ext/intl/JitLocaleFilterMatches.php');
        $this->assertStringContainsString('LocaleFilterMatchesRuntime::invoke', $lowering);
        $this->assertStringContainsString('VmLocale::filterMatches', $lowering);

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/LocaleFilterMatchesRuntime.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $runtime);
        $this->assertStringContainsString('filterMatchesArgv', $runtime);
        $this->assertStringContainsString('__phpc_jit_locale_filter_matches', $runtime);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $runtime);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $runtime);

        $method = (string) file_get_contents(__DIR__.'/../../ext/intl/LocaleFilterMatches.php');
        $this->assertStringContainsString('JitLocaleFilterMatches::filterMatches', $method);

        $ctx = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("functionProxies['locale::filtermatches']", $ctx);
    }

    public function testJitHelperDelegatesToVmLocale(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/intl/LocaleFilterMatchesJitHelper.php');
        $this->assertStringContainsString('VmLocale::filterMatches', $source);
        $this->assertStringContainsString('filterMatchesArgv', $source);
    }

    public function testSpineBundleIncludesLocaleFilterMatchesJit(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitLocaleFilterMatches.php', $spine);
        $this->assertStringContainsString('LocaleFilterMatchesJitHelper.php', $spine);
        $this->assertStringContainsString('LocaleFilterMatchesRuntime.php', $spine);
    }

    public function testJitHelperFilterMatchesArgvMatchesPhpSrc(): void
    {
        $this->assertSame(1, LocaleFilterMatchesJitHelper::filterMatchesArgv('de-DE', 'de', 0));
        $this->assertSame(0, LocaleFilterMatchesJitHelper::filterMatchesArgv('en_US@currency=usd', 'en_US', 0));
        $this->assertSame(1, LocaleFilterMatchesJitHelper::filterMatchesArgv('en_US@currency=usd', 'en_US', 1));
        $this->assertSame(0, LocaleFilterMatchesJitHelper::filterMatchesArgv('i-klingon', 'tlh', 0));
        $this->assertSame(1, LocaleFilterMatchesJitHelper::filterMatchesArgv('i-klingon', 'tlh', 1));
    }
}
