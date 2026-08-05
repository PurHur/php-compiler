<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * locale_get_default() JIT/AOT: JitVmHelperLink + LocaleParserJitHelper (#27369, re-#9576).
 */
final class LocaleGetDefaultRuntimeShrinkTest extends TestCase
{
    public function testLocaleGetDefaultBuiltinRoutesThroughJitLocaleParser(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/intl/locale_get_default.php');
        $this->assertStringContainsString('JitLocaleParser::getDefault', $source);
        $this->assertStringNotContainsString('deferred; use VM', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
    }

    public function testLocaleParserLinksGetDefaultBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/LocaleParser.php');
        $this->assertStringContainsString('__phpc_jit_locale_get_default', $source);
        $this->assertStringContainsString('locale_get_default_bridge_entry', $source);
        $this->assertStringContainsString('getDefaultArgv', $source);
        $this->assertStringContainsString('LocaleDefaultJitHelper', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
    }

    public function testLocaleDefaultJitHelperAvoidsVmLocale(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/intl/LocaleDefaultJitHelper.php');
        $this->assertStringContainsString('GetenvLookupJitHelper::fromEnviron', $source);
        $this->assertStringNotContainsString('return VmLocale::', $source);
    }
}
