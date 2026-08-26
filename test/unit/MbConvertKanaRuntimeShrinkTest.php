<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * mb_convert_kana JIT routes through MbConvertKanaJitHelper assertEncodingArgv (#35193).
 */
final class MbConvertKanaRuntimeShrinkTest extends TestCase
{
    public function testMbConvertKanaCompilesMbConvertKanaJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MbConvertKanaRuntime.php');
        $this->assertStringContainsString('MbConvertKanaJitHelper::assertEncodingArgv', $source);
        $this->assertStringContainsString('/ext/mbstring/MbConvertKanaJitHelper.php', $source);
    }

    public function testMbConvertKanaUsesJitVmHelperLink(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MbConvertKanaRuntime.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString("putenv('PHP_COMPILER_SELFHOST_AOT", $source);
    }
}
