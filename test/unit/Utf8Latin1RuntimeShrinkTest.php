<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\Utf8Latin1JitHelper;
use PHPCompiler\ext\standard\VmUtf8Latin1;
use PHPUnit\Framework\TestCase;

/**
 * StringUtf8Latin1 NestedJIT via JitVmHelperLink::ensureCompiled (#22701 / peer #22683).
 * Routes through Utf8Latin1JitHelper PHP not StringUtf8Latin1Jit LLVM (#9912).
 */
final class Utf8Latin1RuntimeShrinkTest extends TestCase
{
    public function testStringUtf8Latin1UsesJitVmHelperLink(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringUtf8Latin1.php');
        $this->assertStringContainsString('Utf8Latin1JitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiledBundle', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('StringUtf8Latin1Jit::', $source);
        $this->assertLessThan(200, \substr_count($source, "\n") + 1);
    }

    public function testUtf8Latin1JitHelperFileDeleted(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringUtf8Latin1Jit.php');
    }

    public function testUtf8Latin1JitHelperDelegatesToVmUtf8Latin1(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/Utf8Latin1JitHelper.php');
        $this->assertStringContainsString('VmUtf8Latin1::encode', $source);
        $this->assertStringContainsString('VmUtf8Latin1::decode', $source);
    }

    public function testUtf8Latin1JitHelperSemanticsMatchVmUtf8Latin1(): void
    {
        $latin1 = "\xE9";
        $this->assertSame(VmUtf8Latin1::encode($latin1), Utf8Latin1JitHelper::encode($latin1));
        $utf8 = VmUtf8Latin1::encode($latin1);
        $this->assertSame(VmUtf8Latin1::decode($utf8), Utf8Latin1JitHelper::decodeArgv($utf8));
        $this->assertSame('', Utf8Latin1JitHelper::encode(''));
    }
}
