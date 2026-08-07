<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * locale_get_* JIT: always JitVmHelperLink + LocaleParserJitHelper (#17072, #20101).
 */
final class LocaleParserRuntimeShrinkTest extends TestCase
{
    public function testLocaleParserAlwaysUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/LocaleParser.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('LocaleParserJitHelper', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('shouldDefer', $source);
    }

    public function testLocaleParserJitHelperDelegatesToVmLocale(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/intl/LocaleParserJitHelper.php');
        $this->assertStringContainsString('VmLocale::getPrimaryLanguage', $source);
        $this->assertStringContainsString('VmLocale::getRegion', $source);
        $this->assertStringContainsString('VmLocale::getScript', $source);
        $this->assertStringContainsString('VmLocale::canonicalize', $source);
        $this->assertStringContainsString('canonicalizeArgv', $source);
        $this->assertStringContainsString('VmLocale::acceptFromHttp', $source);
        $this->assertStringContainsString('acceptFromHttpArgv', $source);
    }

    public function testLocaleDefaultJitHelperDelegatesSemantics(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/intl/LocaleDefaultJitHelper.php');
        $this->assertStringContainsString('getDefaultArgv', $source);
        $this->assertStringContainsString('GetenvLookupJitHelper::fromEnviron', $source);
    }

    public function testSpineBundleIncludesLocaleParserHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('LocaleParser.php', $spine);
        $this->assertStringContainsString('LocaleParserJitHelper.php', $spine);
        $this->assertStringContainsString('LocaleDefaultJitHelper.php', $spine);
        $this->assertStringContainsString('JitLocaleParser.php', $spine);
    }
}
