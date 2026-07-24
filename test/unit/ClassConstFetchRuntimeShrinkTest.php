<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** ClassConstFetchHelper must delegate dynamic lowering to Builtin runtime split (#10200). */
final class ClassConstFetchRuntimeShrinkTest extends TestCase
{
    public function testHelperDelegatesToRuntimeSplit(): void
    {
        $helper = (string) file_get_contents(__DIR__.'/../../lib/JIT/ClassConstFetchHelperTrait.php');
        $this->assertStringContainsString('ClassConstFetchRuntime::fetchDynamicByClassIdValue', $helper);
        $this->assertStringNotContainsString('class_const_dyn_try_', $helper);
        $this->assertFileExists(__DIR__.'/../../lib/JIT/Builtin/ClassConstFetchRuntime.php');
    }

    public function testRuntimeRoutesStrcasecmpThroughCaseCompareJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ClassConstFetchRuntime.php');
        $this->assertStringContainsString('StringCaseCompare::ensureStrcasecmpLinked', $source);
        $this->assertStringNotContainsString('ensureStrCaseCmp', $source);
        $this->assertStringNotContainsString("addFunction('strcasecmp'", $source);
    }

    public function testClassConstFetchHelperRoutesStrcasecmpThroughCaseCompare(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/ClassConstFetchHelperTrait.php');
        $this->assertStringContainsString('StringCaseCompare::ensureStrcasecmpLinked', $source);
        $this->assertStringNotContainsString("addFunction('strcasecmp'", $source);
    }

    /** Spine inventory is alphabetical (Helper before Trait); entry must self-require (#22642). */
    public function testHelperEntrypointRequiresTraitBeforeUse(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/ClassConstFetchHelper.php');
        $requirePos = strpos($source, "require_once __DIR__ . '/ClassConstFetchHelperTrait.php'");
        $usePos = strpos($source, 'use ClassConstFetchHelperTrait');
        $this->assertNotFalse($requirePos);
        $this->assertNotFalse($usePos);
        $this->assertLessThan($usePos, $requirePos);
    }

    /**
     * Incremental AOT compiles each require as its own Script. Helper's `use Trait`
     * must not CompileFatal when the trait AST is in a prior include unit (#22642).
     */
    public function testSeparateIncludeUnitsCompileHelperAfterTrait(): void
    {
        $runtime = new \PHPCompiler\Runtime();
        $trait = (string) file_get_contents(__DIR__.'/../../lib/JIT/ClassConstFetchHelperTrait.php');
        $helper = (string) file_get_contents(__DIR__.'/../../lib/JIT/ClassConstFetchHelper.php');
        $this->assertNotNull(
            $runtime->parseAndCompile($trait, 'ClassConstFetchHelperTrait.php'),
            'trait unit must compile'
        );
        $block = $runtime->parseAndCompile($helper, 'ClassConstFetchHelper.php');
        $this->assertNotNull($block, 'helper unit must compile after trait unit (#22642)');
    }
}

