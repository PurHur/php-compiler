<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** IncludeHelper must delegate to PHP SSOT helpers (#10063). */
final class IncludeHelperShrinkTest extends TestCase
{
    private const BASELINE_LOC = 1072;

    public function testIncludeHelperDelegatesToIncludeJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/IncludeHelper.php');
        $this->assertStringContainsString('IncludeJitHelper::', $source);
        $this->assertStringContainsString('IncludeBindingJitHelper::', $source);
        $this->assertStringContainsString('IncludeBindingEmitHelper::', $source);
        $this->assertStringContainsString('VmInclude::isCatchableSyntaxParseThrowable', $source);
        $this->assertStringContainsString('emitCatchableClassError', $source);
        $this->assertStringContainsString("'ParseError'", $source);
        $this->assertStringNotContainsString('function shouldSkipSelfHostSpineCliInclude', $source);
        $this->assertStringNotContainsString('function shouldStubM3SidecarHostNonLiteralInclude', $source);
        $this->assertStringNotContainsString('function resolveLiteralPath', $source);
        $this->assertStringNotContainsString('function collectCalleeLocalBindings', $source);
        $this->assertStringNotContainsString('function prepareCallerBinding', $source);
        // Private JIT::assignOperand is unusable from IncludeHelper — public Forced API (#21905).
        $this->assertStringContainsString('assignOperandForced(', $source);
        $this->assertStringNotContainsString('$jit->assignOperand(', $source);
    }

    public function testIncludeHelperShrunkAtLeastFiftyPercent(): void
    {
        $loc = substr_count((string) file_get_contents(__DIR__.'/../../lib/JIT/IncludeHelper.php'), "\n") + 1;
        $this->assertLessThanOrEqual((int) floor(self::BASELINE_LOC * 0.5), $loc, 'IncludeHelper.php LOC');
    }

    public function testIncludeJitHelperExistsWithResolveLiteralPath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/IncludeJitHelper.php');
        $this->assertStringContainsString('VmInclude', $source);
        $this->assertStringContainsString('resolveLiteralPath', $source);
    }

    public function testIncludeBindingJitHelperExists(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/IncludeBindingJitHelper.php');
        $this->assertStringContainsString('collectCalleeLocalBindings', $source);
        $this->assertStringContainsString('resolveIncludeCallerVar', $source);
    }
}
