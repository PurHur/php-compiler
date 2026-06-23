<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\Utf8Latin1JitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** StringUtf8Latin1 routes through Utf8Latin1JitHelper PHP not StringUtf8Latin1Jit LLVM (#9912). */
final class Utf8Latin1RuntimeShrinkTest extends TestCase
{
    public function testStringUtf8Latin1RoutesThroughJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringUtf8Latin1.php');
        $this->assertStringContainsString('Utf8Latin1JitHelper', $source);
        $this->assertStringNotContainsString('StringUtf8Latin1Jit::', $source);
        $this->assertLessThan(150, \substr_count($source, "\n") + 1);
    }

    public function testUtf8Latin1JitHelperFileDeleted(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringUtf8Latin1Jit.php');
    }

    public function testUtf8Latin1JitHelperDelegatesToVmString(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/Utf8Latin1JitHelper.php');
        $this->assertStringContainsString('VmString::utf8_encode', $source);
        $this->assertStringContainsString('VmString::utf8_decode', $source);
    }

    public function testUtf8Latin1JitHelperSemanticsMatchVmString(): void
    {
        $latin1 = "\xE9";
        $this->assertSame(VmString::utf8_encode($latin1), Utf8Latin1JitHelper::encode($latin1));
        $utf8 = VmString::utf8_encode($latin1);
        $this->assertSame(VmString::utf8_decode($utf8), Utf8Latin1JitHelper::decode($utf8));
        $this->assertSame('', Utf8Latin1JitHelper::encode(''));
    }
}
