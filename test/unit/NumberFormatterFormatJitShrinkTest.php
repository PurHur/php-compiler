<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** NumberFormatter::format JIT routes through NestedJIT helper (#28648). */
final class NumberFormatterFormatJitShrinkTest extends TestCase
{
    public function testBuiltinRoutesThroughJitHelperNotRefuse(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/intl/numfmt_format.php');
        $this->assertStringContainsString('JitNumberFormatterFormat::invokeProcedural', $builtin);
        $this->assertStringNotContainsString('not implemented for JIT', $builtin);

        $method = (string) file_get_contents(__DIR__.'/../../ext/intl/VmNumberFormatter.php');
        $this->assertStringContainsString('JitNumberFormatterFormat::invokeMethod', $method);

        $lowering = (string) file_get_contents(__DIR__.'/../../ext/intl/JitNumberFormatterFormat.php');
        $this->assertStringContainsString('NumberFormatterFormatRuntime::invoke', $lowering);

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/NumberFormatterFormatRuntime.php');
        $this->assertStringContainsString('NumberFormatterFormatJitHelper', $runtime);
        $this->assertStringContainsString('phpc_numberformatter_format_decimal', $runtime);

        $helper = (string) file_get_contents(__DIR__.'/../../ext/intl/NumberFormatterFormatJitHelper.php');
        $this->assertStringContainsString('formatDecimalArgv', $helper);

        $ctx = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("functionProxies['numberformatter::format']", $ctx);
    }

    public function testJitHelperFormatsDecimal(): void
    {
        $this->assertSame(
            '12.5',
            \PHPCompiler\ext\intl\NumberFormatterFormatJitHelper::formatDecimalArgv(12.5)
        );
        $this->assertSame(
            '0',
            \PHPCompiler\ext\intl\NumberFormatterFormatJitHelper::formatDecimalArgv(0.0)
        );
        $this->assertSame(
            '-3.25',
            \PHPCompiler\ext\intl\NumberFormatterFormatJitHelper::formatDecimalArgv(-3.25)
        );
    }
}
