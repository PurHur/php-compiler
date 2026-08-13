<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmLocalePure;
use PHPUnit\Framework\TestCase;

/**
 * Standalone AOT matches zend_reset_lc_ctype_locale for idle CODESET (#30789).
 */
final class LocaleStartupRuntimeShrinkTest extends TestCase
{
    public function testStandaloneMainEmitsZendLcCtypeReset(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString(
            'LocaleStartupRuntime::emitResetLcCtypeForStandaloneMain',
            $context
        );
        $this->assertStringContainsString('#30789', $context);

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/LocaleStartupRuntime.php');
        $this->assertStringContainsString('C.UTF-8', $runtime);
        $this->assertStringContainsString('zend_reset_lc_ctype_locale', $runtime);
        $this->assertStringContainsString('__phpc_zend_reset_lc_ctype', $runtime);
        $this->assertStringContainsString("lookupFunction('setlocale')", $runtime);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $runtime);
    }

    public function testVmLocalePureResetMatchesZend(): void
    {
        if (!\function_exists('setlocale') || !\defined('LC_CTYPE') || !\defined('CODESET')) {
            $this->markTestSkipped('locale APIs unavailable');
        }

        $previous = @\setlocale(\LC_CTYPE, '0');
        try {
            @\setlocale(\LC_CTYPE, 'C');
            $this->assertSame('ANSI_X3.4-1968', \nl_langinfo(\CODESET));

            VmLocalePure::resetLcCtypeLocale();
            $codeset = \nl_langinfo(\CODESET);
            $ctype = @\setlocale(\LC_CTYPE, '0');
            if (false === @\setlocale(\LC_CTYPE, 'C.UTF-8')) {
                $this->assertSame('C', $ctype);
                $this->assertSame('ANSI_X3.4-1968', $codeset);

                return;
            }
            // Re-apply reset result after the probe above.
            VmLocalePure::resetLcCtypeLocale();
            $this->assertSame('C.UTF-8', @\setlocale(\LC_CTYPE, '0'));
            $this->assertSame('UTF-8', \nl_langinfo(\CODESET));
        } finally {
            if (\is_string($previous) && '' !== $previous) {
                @\setlocale(\LC_CTYPE, $previous);
            }
        }
    }

    public function testSpineBundleIncludesLocaleStartupRuntime(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('LocaleStartupRuntime.php', $spine);
    }
}
