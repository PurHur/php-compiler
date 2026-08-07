<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\intl\MessageFormatterFormatJitHelper;
use PHPUnit\Framework\TestCase;

/** MessageFormatter::format JIT CT fold + NestedJIT fallback (#28655). */
final class MessageFormatterFormatJitShrinkTest extends TestCase
{
    public function testBuiltinRoutesThroughJitHelperNotRefuse(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/intl/msgfmt_format.php');
        $this->assertStringContainsString('JitMessageFormatterFormat::invokeProcedural', $builtin);
        $this->assertStringNotContainsString('not implemented for JIT', $builtin);

        $method = (string) file_get_contents(__DIR__.'/../../ext/intl/VmMessageFormatter.php');
        $this->assertStringContainsString('JitMessageFormatterFormat::invokeMethod', $method);
        $this->assertStringContainsString('JitMessageFormatterConstruct::invoke', $method);

        $lowering = (string) file_get_contents(__DIR__.'/../../ext/intl/JitMessageFormatterFormat.php');
        $this->assertStringContainsString('tryCompileTimeFold', $lowering);
        $this->assertStringContainsString('MessageFormatterFormatRuntime::invoke', $lowering);

        $ctx = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("functionProxies['messageformatter::format']", $ctx);
        $this->assertStringContainsString("functionProxies['messageformatter::__construct']", $ctx);
    }

    public function testHostHelperFormatsNamedPlaceholder(): void
    {
        $this->assertSame(
            'Hello World',
            MessageFormatterFormatJitHelper::formatNamed('Hello {name}', 'name', 'World')
        );
        $this->assertSame(
            'Hello World',
            MessageFormatterFormatJitHelper::helloWorldArgv('x')
        );
    }
}
