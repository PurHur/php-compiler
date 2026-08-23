<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** iconv_substr() JIT routes through IconvStringJitHelper PHP (#27197 / #34272). */
final class IconvSubstrRuntimeShrinkTest extends TestCase
{
    public function testIconvSubstrUsesJitIconvString(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/iconv/IconvStringFunction.php');
        $this->assertStringContainsString('JitIconvString::dispatch', $source);
    }

    public function testIconvStringJitHelperIsNestedJitSafePeel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/iconv/IconvStringJitHelper.php');
        $this->assertStringContainsString('substrArgv', $source);
        $this->assertStringNotContainsString('return VmIconv', $source);
        $this->assertStringContainsString('\\strlen', $source);
    }

    public function testStringIconvSubstrUsesJitVmHelperLinkEnsureCompiled(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringIconvSubstr.php');
        $this->assertStringContainsString('::substrArgv', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('helperFunction', $source);
        $this->assertStringNotContainsString('ensureBridge', $source);
        $this->assertStringNotContainsString('__compiler_iconv_substr', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
    }

    public function testJitIconvStringUsesCallHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/iconv/JitIconvString.php');
        $this->assertStringContainsString('JitNestedHelperCoerce::callHelper', $source);
        $this->assertStringContainsString('StringIconvSubstr::helperFunction', $source);
    }

    public function testSpineBundleIncludesIconvSubstrHelpers(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('IconvStringJitHelper.php', $spine);
        $this->assertStringContainsString('StringIconvSubstr.php', $spine);
        $this->assertStringContainsString('JitIconvString.php', $spine);
    }
}
