<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\PregEmptyPatternReplace;
use PHPCompiler\ext\standard\PregEmptyPatternReplaceJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * PregEmptyPatternReplaceRuntime routes through JitVmHelperLink::ensureCompiledBundle
 * (#11024 / #27432 / peer #27416 / #22842).
 */
final class PregEmptyPatternReplaceRuntimeShrinkTest extends TestCase
{
    public function testPregEmptyPatternReplaceRuntimeUsesJitVmHelperLinkNotHandRolledNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PregEmptyPatternReplaceRuntime.php');
        $this->assertStringContainsString('PregEmptyPatternReplaceJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiledBundle', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringContainsString('/ext/standard/PregEmptyPatternReplace.php', $source);
        $this->assertStringContainsString('/ext/standard/PregEmptyPatternReplaceJitHelper.php', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('putenv', $source);
        $this->assertStringNotContainsString('PHP_COMPILER_SELFHOST_AOT', $source);
        $this->assertStringNotContainsString('captureInsertBlock', $source);
        $this->assertStringNotContainsString('restoreInsertBlock', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/phpc_preg_empty_pattern_replace.c');
    }

    public function testPregEmptyPatternReplaceJitHelperAlignsWithSsot(): void
    {
        $this->assertSame(
            'xaxbxcx',
            PregEmptyPatternReplaceJitHelper::replace('//', 'x', 'abc', -1)
        );
        $count = 0;
        $this->assertSame(
            'xaxbxcx',
            PregEmptyPatternReplace::replace('x', 'abc', -1, 0, $count)
        );
        $this->assertSame(4, $count);
    }
}
