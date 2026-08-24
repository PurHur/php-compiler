<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * mb_encode/decode_mimeheader JIT routes through MbMimeheaderJitHelper PHP (#34299).
 *
 * NestedJIT via {@see \PHPCompiler\JIT\JitVmHelperLink::ensureCompiled} (peer MbConvertKana #34294).
 */
final class MbMimeheaderRuntimeShrinkTest extends TestCase
{
    public function testMbMimeheaderCompilesMbMimeheaderJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MbMimeheaderRuntime.php');
        $this->assertStringContainsString('MbMimeheaderJitHelper::encodeArgv', $source);
        $this->assertStringContainsString('MbMimeheaderJitHelper::decodeArgv', $source);
        $this->assertStringContainsString('/ext/mbstring/MbMimeheaderJitHelper.php', $source);
    }

    public function testMbMimeheaderUsesJitVmHelperLink(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MbMimeheaderRuntime.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiledBundle', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString("putenv('PHP_COMPILER_SELFHOST_AOT", $source);
    }
}
