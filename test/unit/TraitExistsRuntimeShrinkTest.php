<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** trait_exists() JIT routes through TraitExistsJitHelper PHP not inline LLVM (#16173). */
final class TraitExistsRuntimeShrinkTest extends TestCase
{
    public function testJitTraitExistsDelegatesToStringTraitExistsBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitTraitExists.php');
        $this->assertStringContainsString('StringTraitExists::invoke', $source);
        $this->assertStringNotContainsString("lookupFunction('strcasecmp')", $source);
        $this->assertStringNotContainsString('traitClassLowerNames', $source);
        $this->assertLessThan(35, \substr_count($source, "\n") + 1);
    }

    public function testStringTraitExistsUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringTraitExists.php');
        $this->assertStringContainsString('TraitExistsJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink', $source);
        $this->assertStringNotContainsString("lookupFunction('strcasecmp')", $source);
    }
}
