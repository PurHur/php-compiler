<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** datefmt_create / IntlDateFormatter::format JIT routes through allocate helper (#27361). */
final class IntlDateFormatterCreateJitShrinkTest extends TestCase
{
    public function testBuiltinRoutesThroughJitCreateNotRefuse(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/intl/datefmt_create.php');
        $this->assertStringContainsString('JitIntlDateFormatterCreate::invoke', $builtin);
        $this->assertStringNotContainsString('not implemented for JIT', $builtin);
        $this->assertStringNotContainsString('issue #20837', $builtin);

        $formatBuiltin = (string) file_get_contents(__DIR__.'/../../ext/intl/datefmt_format.php');
        $this->assertStringContainsString('JitIntlDateFormatterFormat::invokeProcedural', $formatBuiltin);
        $this->assertStringNotContainsString('not implemented for JIT', $formatBuiltin);

        $method = (string) file_get_contents(__DIR__.'/../../ext/intl/IntlDateFormatterCreate.php');
        $this->assertStringContainsString('JitIntlDateFormatterCreate::invoke', $method);

        $formatMethod = (string) file_get_contents(__DIR__.'/../../ext/intl/IntlDateFormatterFormat.php');
        $this->assertStringContainsString('JitIntlDateFormatterFormat::invokeMethod', $formatMethod);

        $lowering = (string) file_get_contents(__DIR__.'/../../ext/intl/JitIntlDateFormatterCreate.php');
        $this->assertStringContainsString("lookup('IntlDateFormatter')", $lowering);
        $this->assertStringContainsString('__value__writeObject', $lowering);

        $formatLowering = (string) file_get_contents(__DIR__.'/../../ext/intl/JitIntlDateFormatterFormat.php');
        $this->assertStringContainsString('DateTimeFormatRuntime::invoke', $formatLowering);

        $ctx = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("functionProxies['intldateformatter::create']", $ctx);
        $this->assertStringContainsString("functionProxies['intldateformatter::format']", $ctx);

        $call = (string) file_get_contents(__DIR__.'/../../lib/JIT/Call/IntlDateFormatterCreate.php');
        $this->assertStringContainsString('JitIntlDateFormatterCreate::invoke', $call);
    }
}
