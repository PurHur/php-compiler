<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StrPadJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/**
 * str_pad() thin AOT uses native phpc_str_pad_r1 (#36388); helper remains SSOT peer.
 */
final class StrPadRuntimeShrinkTest extends TestCase
{
    public function testStringStrPadUsesNativeR1NotNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrPad.php');
        $this->assertStringContainsString('StrPadRuntime', $source);
        $this->assertStringContainsString('phpc_str_pad_r1', $source);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitStrPad.php');

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StrPadRuntime.php');
        $this->assertStringContainsString('phpc_str_pad_r1', $runtime);
        $this->assertStringContainsString('__string__alloc', $runtime);
        $this->assertStringContainsString('__string__separate', $runtime);

        $strPad = (string) file_get_contents(__DIR__.'/../../ext/standard/str_pad.php');
        $this->assertStringContainsString('StringStrPad::invoke', $strPad);
        $this->assertStringContainsString('releaseEphemeralArgAfterCopy', $strPad);
        $this->assertStringNotContainsString('__compiler_str_pad', $strPad);
        $this->assertStringNotContainsString('JitStrPad', $strPad);
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

    public function testSpineBundleIncludesStrPadRuntime(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitStrPad.php', $spine);
        $this->assertStringContainsString('StrPadRuntime.php', $spine);
        $this->assertStringContainsString('StringStrPad.php', $spine);
    }
}
