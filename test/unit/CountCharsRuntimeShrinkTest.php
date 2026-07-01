<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\CountCharsJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\VM\HashTable;
use PHPUnit\Framework\TestCase;

/** count_chars() JIT routes through CountCharsJitHelper PHP not inline LLVM (#14692). */
final class CountCharsRuntimeShrinkTest extends TestCase
{
    public function testStringCountCharsUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringCountChars.php');
        $this->assertStringContainsString('CountCharsJitHelper', $source);

        $jitCountChars = (string) file_get_contents(__DIR__.'/../../ext/standard/JitCountChars.php');
        $this->assertStringNotContainsString('histogramLoop', $jitCountChars);
        $this->assertStringNotContainsString('emitArrayMode', $jitCountChars);
        $this->assertStringNotContainsString('emitStringMode', $jitCountChars);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/count_chars.php');
        $this->assertStringContainsString('StringCountChars::invoke', $builtin);
    }

    public function testCountCharsJitHelperDelegatesToVmString(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/CountCharsJitHelper.php');
        $this->assertStringContainsString('VmString::count_chars', $source);

        $ht = CountCharsJitHelper::arrayArgv('hello', 0);
        $this->assertInstanceOf(HashTable::class, $ht);
        $vm = VmString::count_chars('hello', 0);
        $this->assertIsArray($vm);
        $this->assertSame($vm[\ord('h')], 1);

        $this->assertSame('ehlo', CountCharsJitHelper::stringArgv('hello', 3));
        $this->assertSame(VmString::count_chars('hello', 4), CountCharsJitHelper::stringArgv('hello', 4));
    }

    public function testSpineBundleIncludesCountCharsJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('CountCharsJitHelper.php', $spine);
        $this->assertStringContainsString('StringCountChars.php', $spine);
    }
}
