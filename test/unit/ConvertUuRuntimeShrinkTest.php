<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ConvertUuJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** convert_uuencode()/convert_uudecode() JIT routes through ConvertUuJitHelper PHP (#13227, #18827). */
final class ConvertUuRuntimeShrinkTest extends TestCase
{
    public function testStringConvertUuUsesJitHelperNotLlvmMonolith(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringConvertUu.php');
        $this->assertStringContainsString('ConvertUuJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('StringConvertUuEncodeLlvm', $source);
        $this->assertStringNotContainsString('StringConvertUuDecodeLlvm', $source);
        $this->assertStringNotContainsString('JitConvertUuBodies', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringConvertUuEncodeLlvm.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringConvertUuDecodeLlvm.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitConvertUuBodies.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringConvertUuJit.php');
    }

    public function testConvertUuJitHelperDelegatesToVmString(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ConvertUuJitHelper.php');
        $this->assertStringContainsString('VmString::convert_uuencode', $source);
        $this->assertStringContainsString('VmString::convert_uudecode', $source);
        $this->assertStringContainsString('TriggerErrorJitHelper::warning', $source);
    }

    public function testConvertUuJitHelperEncodeRoundTrip(): void
    {
        $encoded = ConvertUuJitHelper::encode('hello');
        $this->assertSame(VmString::convert_uuencode('hello'), $encoded);
        ConvertUuJitHelper::resetForTest();
        $this->assertSame(1, ConvertUuJitHelper::decodeTag($encoded));
        $this->assertSame('hello', ConvertUuJitHelper::lastString());
    }

    public function testConvertUuJitHelperDecodeInvalidReturnsFalseTag(): void
    {
        ConvertUuJitHelper::resetForTest();
        $this->assertSame(0, ConvertUuJitHelper::decodeTag('not-uue'));
    }

    public function testSpineBundleOmitsDeletedConvertUuLlvm(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitConvertUuBodies.php', $spine);
        $this->assertStringNotContainsString('StringConvertUuEncodeLlvm.php', $spine);
        $this->assertStringNotContainsString('StringConvertUuDecodeLlvm.php', $spine);
        $this->assertStringContainsString('ConvertUuJitHelper.php', $spine);
        $this->assertStringContainsString('StringConvertUu.php', $spine);
    }
}
