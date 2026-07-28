<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\HighlightEngine;
use PHPCompiler\ext\standard\HighlightJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * Highlight NestedJIT via JitVmHelperLink::ensureCompiled (#24417 / peer #24382).
 */
final class HighlightRuntimeShrinkTest extends TestCase
{
    public function testHighlightUsesJitVmHelperLinkNotHandRolledNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Highlight.php');
        $this->assertStringContainsString('HighlightJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertLessThan(60, \substr_count($source, "\n") + 1);
    }

    public function testHighlightJitHelperDelegatesToHighlightEngine(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/HighlightJitHelper.php');
        $this->assertStringContainsString('HighlightEngine::render', $source);
    }

    public function testHighlightJitHelperSemanticsMatchEngine(): void
    {
        $code = '<?php echo "hi";';
        $this->assertSame(HighlightEngine::render($code), HighlightJitHelper::renderString($code));
    }

    public function testSpineBundleIncludesHighlightJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('HighlightJitHelper.php', $spine);
        $this->assertStringContainsString('lib/JIT/Builtin/Highlight.php', $spine);
    }
}
