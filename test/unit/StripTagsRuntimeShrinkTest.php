<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** StringStripTags JIT/AOT path uses StripTagsJitHelper PHP, not StringStripTagsJit (#9196, #9746). */
final class StripTagsRuntimeShrinkTest extends TestCase
{
    public function testStringStripTagsUsesStripTagsJitHelperForJitPath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStripTags.php');
        $this->assertStringContainsString('StripTagsJitHelper', $source);
        $this->assertStringNotContainsString('StringStripTagsJit', $source);
        $this->assertStringNotContainsString('StringStripTagsStandaloneLlvm', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringStripTagsJit.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringStripTagsStandaloneLlvm.php');
    }

    public function testStripTagsJitHelperDelegatesToVmString(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/StripTagsJitHelper.php');
        $this->assertStringContainsString('VmString::stripTags', $source);
    }

    /** Issue #9196: spine must not require deleted StringStripTagsJit.php. */
    public function testSpineBundleOmitsDeletedStringStripTagsJit(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('StringStripTagsJit.php', $spine);
        $this->assertStringNotContainsString('StringStripTagsStandaloneLlvm.php', $spine);
        $this->assertStringContainsString('StripTagsJitHelper.php', $spine);
    }
}
