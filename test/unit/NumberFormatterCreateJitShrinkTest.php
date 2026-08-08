<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** numfmt_create / NumberFormatter::create JIT routes through allocate helper (#27385). */
final class NumberFormatterCreateJitShrinkTest extends TestCase
{
    public function testBuiltinRoutesThroughJitCreateNotRefuse(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/intl/numfmt_create.php');
        $this->assertStringContainsString('JitNumberFormatterCreate::invoke', $builtin);
        $this->assertStringNotContainsString('not implemented for JIT', $builtin);
        $this->assertStringNotContainsString('issue #20754', $builtin);

        $method = (string) file_get_contents(__DIR__.'/../../ext/intl/VmNumberFormatter.php');
        $this->assertStringContainsString('JitNumberFormatterCreate::invoke', $method);

        $lowering = (string) file_get_contents(__DIR__.'/../../ext/intl/JitNumberFormatterCreate.php');
        $this->assertStringContainsString("lookup('NumberFormatter')", $lowering);
        $this->assertStringContainsString('__value__writeObject', $lowering);

        $ctx = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("functionProxies['numberformatter::create']", $ctx);

        $call = (string) file_get_contents(__DIR__.'/../../lib/JIT/Call/NumberFormatterCreate.php');
        $this->assertStringContainsString('JitNumberFormatterCreate::invoke', $call);
    }
}
