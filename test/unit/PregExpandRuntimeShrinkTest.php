<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\PregExpandJitHelper;
use PHPCompiler\ext\standard\PregReplacementExpand;
use PHPUnit\Framework\TestCase;

/**
 * PregExpandRuntime routes through JitVmHelperLink::ensureCompiledBundle
 * (#10064 / #27456 / peer #27432 / #27416).
 */
final class PregExpandRuntimeShrinkTest extends TestCase
{
    public function testPregExpandRuntimeUsesJitVmHelperLinkNotHandRolledNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PregExpandRuntime.php');
        $this->assertStringContainsString('PregExpandJitHelper', $source);
        $this->assertStringContainsString('PregReplacementExpand', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiledBundle', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringContainsString('/ext/standard/PregReplacementExpand.php', $source);
        $this->assertStringContainsString('/ext/standard/PregExpandJitHelper.php', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('putenv', $source);
        $this->assertStringNotContainsString('PHP_COMPILER_SELFHOST_AOT', $source);
        $this->assertStringNotContainsString('captureInsertBlock', $source);
        $this->assertStringNotContainsString('restoreInsertBlock', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/phpc_preg_expand.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringPregMatchStandaloneLlvm.php');
        $linker = (string) file_get_contents(__DIR__.'/../../lib/AOT/Linker.php');
        $this->assertStringNotContainsString('phpc_preg_expand.c', $linker);
    }

    public function testPregExpandJitHelperMatchesReplacementExpand(): void
    {
        $ovector = [1, 3, 1, 3];
        $packed = pack('q*', 1, 3, 1, 3);
        $this->assertSame(
            PregReplacementExpand::expand('[$1]', $ovector, 2, 'x12y'),
            PregExpandJitHelper::expand('[$1]', $packed, 2, 'x12y')
        );
        $this->assertSame(
            PregReplacementExpand::expand('${1}x', [0, 3, 1, 2], 2, 'a9b'),
            PregExpandJitHelper::expand('${1}x', pack('q*', 0, 3, 1, 2), 2, 'a9b')
        );
    }
}
