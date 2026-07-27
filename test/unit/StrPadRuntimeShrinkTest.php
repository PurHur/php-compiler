<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StrPadJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** str_pad() JIT routes through StrPadJitHelper PHP not inline LLVM (#14863). */
final class StrPadRuntimeShrinkTest extends TestCase
{
    public function testStringStrPadUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrPad.php');
        $this->assertStringContainsString('StrPadJitHelper', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitStrPad.php');

        $strPad = (string) file_get_contents(__DIR__.'/../../ext/standard/str_pad.php');
        $this->assertStringContainsString('StringStrPad::ensureLinked', $strPad);
        $this->assertStringContainsString('__compiler_str_pad', $strPad);
        $this->assertStringNotContainsString('JitStrPad', $strPad);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrPad.php');
        $this->assertStringContainsString('__compiler_str_pad', $bridge);
    }

    public function testStrPadJitHelperInlinesWithoutVmStringCall(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/StrPadJitHelper.php');
        // Must not call VmString — that path nulls/segfaults user-script AOT (#23911 / peer #23204).
        $this->assertStringNotContainsString('VmString::', $source);
        $this->assertStringNotContainsString('return VmString', $source);

        $this->assertSame('  hi', StrPadJitHelper::padArgv('hi', 4, ' ', 0));
        $this->assertSame('  hi', VmString::strPad('hi', 4, ' ', 0));
        $this->assertSame('hi--', StrPadJitHelper::padArgv('hi', 4, '-', 1));
        $this->assertSame('hi--', VmString::strPad('hi', 4, '-', 1));
        $this->assertSame('-hi-', StrPadJitHelper::padArgv('hi', 4, '-', 2));
        $this->assertSame('-hi-', VmString::strPad('hi', 4, '-', 2));
        $this->assertSame('hi', StrPadJitHelper::padArgv('hi', -5, 'x', 1));
        $this->assertSame('hi', VmString::strPad('hi', -5, 'x', 1));
        $this->assertSame('p----', StrPadJitHelper::padArgv('p', 5, '-', 1));
        $this->assertSame('p----', VmString::strPad('p', 5, '-', 1));
    }

    public function testSpineBundleIncludesStrPadJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitStrPad.php', $spine);
        $this->assertStringContainsString('StrPadJitHelper.php', $spine);
        $this->assertStringContainsString('StringStrPad.php', $spine);
    }
}
