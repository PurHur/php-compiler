<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** array_merge_recursive() C runtime shrink (#6021, #6177). */
final class ArrayMergeRecursiveRuntimeShrinkTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    public function testPhpcArrayMergeRecursiveCRuntimeRemovedFromLinker(): void
    {
        $linker = file_get_contents($this->repoRoot.'/lib/AOT/Linker.php');
        $this->assertIsString($linker);
        $this->assertStringNotContainsString('phpc_array_merge_recursive.c', $linker);
        $this->assertFileDoesNotExist($this->repoRoot.'/lib/AOT/runtime/phpc_array_merge_recursive.c');
        $this->assertFileDoesNotExist($this->repoRoot.'/lib/JIT/Builtin/phpc_array_merge_recursive.c');
    }

    public function testJitLoweringUsesArrayBuiltinHelperOverlay(): void
    {
        $helper = file_get_contents($this->repoRoot.'/lib/JIT/ArrayBuiltinHelper.php');
        $this->assertIsString($helper);
        $this->assertStringContainsString('array_merge_recursive', $helper);
        $this->assertStringContainsString('mergeRecursiveOverlay', $helper);

        $builtin = file_get_contents($this->repoRoot.'/ext/standard/array_merge_recursive.php');
        $this->assertIsString($builtin);
        $this->assertStringContainsString('mergeRecursiveCopy', $builtin);

        $jit = file_get_contents($this->repoRoot.'/ext/standard/JitArrayMergeRecursive.php');
        $this->assertIsString($jit);
        $this->assertStringContainsString('mergeRecursiveOverlay', $jit);
    }
}
