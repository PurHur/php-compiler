<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** StringStripTags JIT/AOT path uses StripTagsJitHelper + JitVmHelperLink (#9196, #9746, #21711). */
final class StripTagsRuntimeShrinkTest extends TestCase
{
    public function testStringStripTagsUsesStripTagsJitHelperForJitPath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStripTags.php');
        $this->assertStringContainsString('StripTagsJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('ensureJitHelperCompiled', $source);
        $this->assertStringNotContainsString('StringStripTagsJit', $source);
        $this->assertStringNotContainsString('StringStripTagsStandaloneLlvm', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringStripTagsJit.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringStripTagsStandaloneLlvm.php');
    }

    public function testStripTagsJitHelperDelegatesToVmString(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/StripTagsJitHelper.php');
        $this->assertStringContainsString('VmString::stripTags', $source);

        $this->assertSame('hello', \PHPCompiler\ext\standard\StripTagsJitHelper::stripTags('<b>hello</b>', ''));
        $this->assertSame('<b>hello</b>', \PHPCompiler\ext\standard\StripTagsJitHelper::stripTags('<b>hello</b>', '<b>'));
    }

    public function testStripTagsCallFoldsCompileTimeStringAllowedTags(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/strip_tags.php');
        $this->assertStringContainsString('resolveAllowedTagsJit($allowed)', $source);
        $this->assertStringContainsString('valueBoxHashtable', $source);
        $this->assertStringNotContainsString(
            'null === $allowed || self::isAllowedTagsArrayArg($allowed)',
            $source,
            'string allowed_tags literals must fold (#21711)'
        );
    }

    /** Issue #9196: spine must not require deleted StringStripTagsJit.php. */
    public function testSpineBundleOmitsDeletedStringStripTagsJit(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('StringStripTagsJit.php', $spine);
        $this->assertStringNotContainsString('StringStripTagsStandaloneLlvm.php', $spine);
        $this->assertStringContainsString('StripTagsJitHelper.php', $spine);
        $this->assertStringContainsString('StringStripTags.php', $spine);
    }
}
