<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ConvertUuJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** convert_uuencode/uudecode JIT routes through ConvertUuJitHelper PHP not StringConvertUuJit LLVM (#13227). */
final class ConvertUuRuntimeShrinkTest extends TestCase
{
    public function testStringConvertUuIsThinBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringConvertUu.php');
        $this->assertStringContainsString('ConvertUuJitHelper', $source);
        $this->assertStringNotContainsString('StringConvertUuJit', $source);
        $this->assertLessThan(230, \substr_count($source, "\n") + 1);
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
}
