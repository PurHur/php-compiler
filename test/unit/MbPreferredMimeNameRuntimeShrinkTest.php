<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * mb_preferred_mime_name JIT routes through MbPreferredMimeNameJitHelper PHP (#34298).
 *
 * NestedJIT via {@see \PHPCompiler\JIT\JitVmHelperLink::ensureCompiled} (peer MbConvertKana #34294).
 */
final class MbPreferredMimeNameRuntimeShrinkTest extends TestCase
{
    public function testMbPreferredMimeNameCompilesHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MbPreferredMimeNameRuntime.php');
        $this->assertStringContainsString('MbPreferredMimeNameJitHelper::preferredArgv', $source);
        $this->assertStringContainsString('/ext/mbstring/MbPreferredMimeNameJitHelper.php', $source);
    }

    public function testMbPreferredMimeNameUsesJitVmHelperLink(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MbPreferredMimeNameRuntime.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString("putenv('PHP_COMPILER_SELFHOST_AOT", $source);
    }
}
