<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * UTF-8 ABI: LLVM {@see __string__*} walks via StringUtf8*Jit; PHP SSOT stays VmString (#27051).
 */
final class Utf8JitRuntimeShrinkTest extends TestCase
{
    public function testUtf8JitHelperDelegatesToVmString(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/Utf8JitHelper.php');
        $this->assertStringContainsString('VmString::utf8CharLength', $source);
        $this->assertStringContainsString('VmString::isValidUtf8', $source);
    }

    public function testStringUtf8RuntimeDelegatesToLlvmStringAbiBodies(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringUtf8Runtime.php');
        $this->assertStringContainsString('StringUtf8StrlenJit::implement', $source);
        $this->assertStringContainsString('StringUtf8ValidJit::implement', $source);
        $this->assertStringContainsString('#27051', $source);
        $this->assertStringNotContainsString('JitVmHelperLink', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('Utf8JitHelper::utf8CharLength', $source);
        $this->assertFileExists(__DIR__.'/../../lib/JIT/Builtin/StringUtf8StrlenJit.php');
        $this->assertFileExists(__DIR__.'/../../lib/JIT/Builtin/StringUtf8ValidJit.php');
        $this->assertLessThan(80, \substr_count($source, "\n") + 1);
    }

    public function testLlvmBodiesOperateOnStringPointerNotValueBox(): void
    {
        $strlen = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringUtf8StrlenJit.php');
        $valid = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringUtf8ValidJit.php');
        foreach ([$strlen, $valid] as $source) {
            $this->assertStringContainsString('__string__strlen', $source);
            $this->assertStringContainsString('structFieldMap[\'__string__\']', $source);
            $this->assertStringNotContainsString('__value__writeString', $source);
            $this->assertStringNotContainsString('JitVmHelperLink', $source);
        }
    }

    public function testMbstringJitRoutesThroughStringUtf8Runtime(): void
    {
        $strlen = (string) \file_get_contents(__DIR__.'/../../ext/mbstring/JitMbStrlen.php');
        $this->assertStringContainsString('StringUtf8Runtime', $strlen);

        $check = (string) \file_get_contents(__DIR__.'/../../ext/mbstring/JitMbCheckEncoding.php');
        $this->assertStringContainsString('StringUtf8Runtime::validFromPtr', $check);
    }
}
