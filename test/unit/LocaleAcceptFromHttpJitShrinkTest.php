<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Locale::acceptFromHttp JIT routes through JitLocaleParser (#28656). */
final class LocaleAcceptFromHttpJitShrinkTest extends TestCase
{
    public function testBuiltinRoutesThroughJitLocaleParserNotRefuse(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/intl/locale_accept_from_http.php');
        $this->assertStringContainsString('JitLocaleParser::acceptFromHttp', $builtin);
        $this->assertStringNotContainsString('not implemented; use VM', $builtin);

        $method = (string) file_get_contents(__DIR__.'/../../ext/intl/LocaleAcceptFromHttp.php');
        $this->assertStringContainsString('JitLocaleParser::acceptFromHttp', $method);

        $ctx = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("functionProxies['locale::acceptfromhttp']", $ctx);

        $parser = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/LocaleParser.php');
        $this->assertStringContainsString('acceptFromHttpArgv', $parser);
        $this->assertStringContainsString('__phpc_jit_locale_accept_from_http', $parser);
    }

    public function testJitHelperAcceptsEnUsHeader(): void
    {
        $this->assertSame(
            'en_US',
            \PHPCompiler\ext\intl\LocaleParserJitHelper::acceptFromHttpArgv('en-US,en;q=0.9,fr;q=0.8')
        );
    }
}
