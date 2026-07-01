<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\UrlencodeJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** urlencode()/rawurlencode() JIT routes through UrlencodeJitHelper PHP not inline LLVM (#14724). */
final class UrlencodeRuntimeShrinkTest extends TestCase
{
    public function testStringUrlencodeUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringUrlencode.php');
        $this->assertStringContainsString('UrlencodeJitHelper', $source);
        $this->assertStringNotContainsString('countLoop', $source);
        $this->assertStringNotContainsString('formEncoding', $source);
    }

    public function testUrlencodeJitHelperDelegatesToVmString(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/UrlencodeJitHelper.php');
        $this->assertStringContainsString('VmString::urlencode', $source);
        $this->assertStringContainsString('VmString::rawurlencode', $source);

        $this->assertSame('foo+bar', UrlencodeJitHelper::urlencodeArgv('foo bar'));
        $this->assertSame('foo%20bar', UrlencodeJitHelper::rawurlencodeArgv('foo bar'));
        $this->assertSame('foo+bar', VmString::urlencode('foo bar'));
        $this->assertSame('foo%20bar', VmString::rawurlencode('foo bar'));
    }

    public function testSpineBundleIncludesUrlencodeJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('UrlencodeJitHelper.php', $spine);
        $this->assertStringContainsString('StringUrlencode.php', $spine);
    }
}
