<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * mb_strwidth / mb_strimwidth / mb_str_pad JIT routes through MbStrwidthJitHelper PHP (#3495 / #34262).
 *
 * NestedJIT via {@see \PHPCompiler\JIT\JitVmHelperLink::ensureCompiled} (#26617, peer #26598).
 * Runtime int ABI via {@see \PHPCompiler\JIT\JitNestedHelperCoerce::callHelper} (#34262).
 */
final class MbStrwidthRuntimeShrinkTest extends TestCase
{
    public function testMbStrwidthCompilesMbStrwidthJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MbStrwidth.php');
        $this->assertStringContainsString('MbStrwidthJitHelper::strwidth', $source);
        $this->assertStringContainsString('MbStrwidthJitHelper::strimwidth', $source);
        $this->assertStringContainsString('MbStrwidthJitHelper::strPad', $source);
        $this->assertStringContainsString('/ext/mbstring/MbStrwidthJitHelper.php', $source);
    }

    public function testMbStrwidthUsesJitVmHelperLink(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MbStrwidth.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString("putenv('PHP_COMPILER_SELFHOST_AOT", $source);
    }

    public function testJitMbStrwidthRoutesRuntimeViaCallHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/mbstring/JitMbStrwidth.php');
        $this->assertStringContainsString('JitNestedHelperCoerce::callHelper', $source);
        $this->assertStringContainsString('extractStringPtrFromHelperResult', $source);
        $this->assertStringContainsString('BasicBlockHelper::tryGetInsertBlock', $source);
        $helper = (string) \file_get_contents(__DIR__.'/../../ext/mbstring/MbStrwidthJitHelper.php');
        $this->assertStringNotContainsString('return VmMbstring::', $helper);
        $this->assertStringNotContainsString('VmMbstring::strimwidth(', $helper);
        $this->assertStringNotContainsString('EastAsianWidthTable::', $helper);
        $this->assertStringNotContainsString('\\str_repeat(', $helper);
        $this->assertStringContainsString('NestedJIT peel', $helper);
    }
}
