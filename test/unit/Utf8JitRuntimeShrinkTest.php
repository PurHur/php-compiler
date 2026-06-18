<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** UTF-8 JIT helpers route through VmString PHP, not per-byte LLVM loops (#9246). */
final class Utf8JitRuntimeShrinkTest extends TestCase
{
    public function testUtf8JitHelperDelegatesToVmString(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/Utf8JitHelper.php');
        $this->assertStringContainsString('VmString::utf8CharLength', $source);
        $this->assertStringContainsString('VmString::isValidUtf8', $source);
    }

    public function testStringUtf8StrlenNoLongerUsesJitFile(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringUtf8Strlen.php');
        $this->assertStringContainsString('StringUtf8Runtime::ensureStrlenLinked', $source);
        $this->assertStringNotContainsString('StringUtf8StrlenJit', $source);
    }

    public function testStringUtf8ValidNoLongerUsesJitFile(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringUtf8Valid.php');
        $this->assertStringContainsString('StringUtf8Runtime::ensureValidLinked', $source);
        $this->assertStringNotContainsString('StringUtf8ValidJit', $source);
    }
}
