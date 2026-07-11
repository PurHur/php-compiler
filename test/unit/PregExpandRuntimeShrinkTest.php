<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\PregExpandJitHelper;
use PHPCompiler\ext\standard\PregReplacementExpand;
use PHPUnit\Framework\TestCase;

/** preg_replace expansion routes through PHP not phpc_preg_expand.c (#10064). */
final class PregExpandRuntimeShrinkTest extends TestCase
{
    public function testPregExpandRuntimeUsesJitHelperNotCRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PregExpandRuntime.php');
        $this->assertStringContainsString('PregExpandJitHelper', $source);
        $this->assertStringContainsString('PregReplacementExpand', $source);
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
