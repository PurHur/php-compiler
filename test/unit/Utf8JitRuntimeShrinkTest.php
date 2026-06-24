<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** UTF-8 JIT helpers route through Utf8JitHelper PHP via StringUtf8Runtime, not per-byte LLVM (#9246, #9273). */
final class Utf8JitRuntimeShrinkTest extends TestCase
{
    public function testUtf8JitHelperDelegatesToVmString(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/Utf8JitHelper.php');
        $this->assertStringContainsString('VmString::utf8CharLength', $source);
        $this->assertStringContainsString('VmString::isValidUtf8', $source);
    }

    public function testStringUtf8RuntimeUsesNestedJitCompileScope(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringUtf8Runtime.php');
        $this->assertStringContainsString('NestedJitCompileScope', $source);
        $this->assertStringContainsString('Utf8JitHelper', $source);
        $this->assertStringNotContainsString('utf8_strlen_step_ascii', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringUtf8StrlenJit.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringUtf8ValidJit.php');
    }

    public function testThinWrapperFilesDeleted(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringUtf8Strlen.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringUtf8Valid.php');
    }

    public function testMbstringJitRoutesThroughStringUtf8Runtime(): void
    {
        $strlen = (string) \file_get_contents(__DIR__.'/../../ext/mbstring/JitMbStrlen.php');
        $this->assertStringContainsString('StringUtf8Runtime', $strlen);
        $this->assertStringNotContainsString('StringUtf8Strlen', $strlen);

        $check = (string) \file_get_contents(__DIR__.'/../../ext/mbstring/JitMbCheckEncoding.php');
        $this->assertStringContainsString('StringUtf8Runtime::validFromPtr', $check);
        $this->assertStringNotContainsString('StringUtf8Valid', $check);
    }
}
