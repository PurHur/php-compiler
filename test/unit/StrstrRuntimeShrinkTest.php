<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StrstrJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** strstr()/stristr() JIT routes through StrstrJitHelper PHP not inline LLVM (#14778). */
final class StrstrRuntimeShrinkTest extends TestCase
{
    public function testStringStrstrUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrstr.php');
        $this->assertStringContainsString('StrstrJitHelper', $source);
        $this->assertStringNotContainsString('JitStringSearch::findOffsetI32', $source);

        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStrstr.php');
        $this->assertStringContainsString('StringStrstr::invoke', $jit);
        $this->assertStringNotContainsString('string_trim::jitCopySlice', $jit);

        $strstr = (string) file_get_contents(__DIR__.'/../../ext/standard/strstr.php');
        $this->assertStringContainsString('JitStrstr::find', $strstr);

        $stristr = (string) file_get_contents(__DIR__.'/../../ext/standard/stristr.php');
        $this->assertStringContainsString('JitStrstr::find', $stristr);
    }

    public function testStrstrJitHelperDelegatesToVmString(): void
    {
        $this->assertSame('world', StrstrJitHelper::strstrArgv('hello world', 'wor', 0));
        $this->assertNull(StrstrJitHelper::strstrArgv('hello', 'z', 0));
        $this->assertSame('hello ', StrstrJitHelper::strstrArgv('hello world', 'wor', 1));
        $expected = VmString::stristr('Hello World', 'O', false);
        $this->assertSame(
            false === $expected ? null : $expected,
            StrstrJitHelper::stristrArgv('Hello World', 'O', 0)
        );
    }

    public function testSpineBundleIncludesStrstrJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('StrstrJitHelper.php', $spine);
        $this->assertStringContainsString('StringStrstr.php', $spine);
    }
}
