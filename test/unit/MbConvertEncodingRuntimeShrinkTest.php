<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * mb_convert_encoding JIT routes through MbConvertEncodingJitHelper PHP (#34309).
 *
 * NestedJIT via {@see \PHPCompiler\JIT\JitVmHelperLink::ensureCompiled} (peer MbConvertKana #34294).
 */
final class MbConvertEncodingRuntimeShrinkTest extends TestCase
{
    public function testMbConvertEncodingCompilesMbConvertEncodingJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MbConvertEncodingRuntime.php');
        $this->assertStringContainsString('MbConvertEncodingJitHelper::convertArgv', $source);
        $this->assertStringContainsString('MbConvertEncodingJitHelper::assertToEncodingArgv', $source);
        $this->assertStringContainsString('MbConvertEncodingJitHelper::assertFromEncodingArgv', $source);
        $this->assertStringContainsString('/ext/mbstring/MbConvertEncodingJitHelper.php', $source);
        $this->assertStringNotContainsString('convertDefaultArgv', $source);
    }

    public function testMbConvertEncodingUsesJitVmHelperLink(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MbConvertEncodingRuntime.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/mb_convert_encoding.c');
    }
}
