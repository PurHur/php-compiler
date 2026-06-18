<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** StringCslashes must route through CslashesJitHelper PHP, not StringCslashesJit LLVM (#9578). */
final class StringCslashesRuntimeShrinkTest extends TestCase
{
    public function testStringCslashesUsesCslashesJitHelperNotLlvmMask(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringCslashes.php');
        $this->assertStringContainsString('CslashesJitHelper', $source);
        $this->assertStringNotContainsString('StringCslashesJit', $source);
        $this->assertStringNotContainsString('buildMaskFromCharlist', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringCslashesJit.php');
    }

    public function testJitAddcslashesDelegatesToCompilerBridgeNotMaskLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitAddcslashes.php');
        $this->assertStringContainsString('__compiler_addcslashes', $source);
        $this->assertStringNotContainsString('escapeWithMaskSlot', $source);
        $this->assertStringNotContainsString('buildMaskFromCharlist', $source);
    }
}
