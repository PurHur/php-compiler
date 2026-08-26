<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * mb_strwidth / mb_strimwidth / mb_str_pad JIT routes through MbStrwidthJitHelper PHP (#3495).
 *
 * NestedJIT via {@see \PHPCompiler\JIT\JitVmHelperLink::ensureCompiled} (#26617, peer #26598).
 */
final class MbStrwidthRuntimeShrinkTest extends TestCase
{
    public function testMbStrwidthCompilesMbStrwidthJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MbStrwidth.php');
        $this->assertStringContainsString('MbStrwidthJitHelper::strwidth', $source);
        $this->assertStringContainsString('MbStrwidthJitHelper::strimwidth', $source);
        $this->assertStringContainsString('MbStrwidthJitHelper::strPad', $source);
        $this->assertStringContainsString('MbStrwidthJitHelper::assertEncodingArgv', $source);
        $this->assertStringContainsString('/ext/mbstring/MbStrwidthJitHelper.php', $source);
        $helper = (string) \file_get_contents(__DIR__.'/../../ext/mbstring/MbStrwidthJitHelper.php');
        $this->assertStringContainsString('function assertEncodingArgv', $helper);
        $jit = (string) \file_get_contents(__DIR__.'/../../ext/mbstring/JitMbStrwidth.php');
        $this->assertStringContainsString('assertEncodingHelper', $jit);
        $this->assertStringNotContainsString(
            'encoding must be a string literal in this compiler build',
            $jit
        );
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
}
