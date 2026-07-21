<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\HebrevJitHelper;
use PHPCompiler\ext\standard\VmHebrev;
use PHPUnit\Framework\TestCase;

/**
 * hebrev()/hebrevc() JIT routes through HebrevJitHelper + JitVmHelperLink (#3450, #17183, #21828).
 */
final class HebrevRuntimeShrinkTest extends TestCase
{
    public function testHebrevUsesJitVmHelperLinkNotHandRolledNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Hebrev.php');
        $this->assertStringContainsString('HebrevJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
    }

    public function testHebrevcUsesJitVmHelperLinkNotHandRolledNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Hebrevc.php');
        $this->assertStringContainsString('HebrevJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
    }

    public function testHebrevJitHelperDelegatesToVmHebrev(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/HebrevJitHelper.php');
        $this->assertStringContainsString('VmHebrev::convert', $source);
        $this->assertStringContainsString('VmHebrev::convertWithNewlines', $source);

        $input = "shalom\n";
        $this->assertSame(VmHebrev::convert($input, 0), HebrevJitHelper::convert($input, 0));
        $this->assertSame(
            VmHebrev::convertWithNewlines($input, 0),
            HebrevJitHelper::convertWithNewlines($input, 0)
        );
    }

    public function testSpineBundleIncludesHebrevJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('HebrevJitHelper.php', $spine);
        $this->assertStringContainsString('Hebrev.php', $spine);
        $this->assertStringContainsString('Hebrevc.php', $spine);
    }
}
