<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** array_replace_recursive() C runtime shrink (#5252, #6022). */
final class ArrayReplaceRecursiveRuntimeShrinkTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    public function testPhpcArrayReplaceRecursiveCRuntimeRemovedFromLinker(): void
    {
        $linker = file_get_contents($this->repoRoot.'/lib/AOT/Linker.php');
        $this->assertIsString($linker);
        $this->assertStringNotContainsString('phpc_array_replace_recursive.c', $linker);
        $this->assertFileDoesNotExist($this->repoRoot.'/lib/AOT/runtime/phpc_array_replace_recursive.c');
        $this->assertFileDoesNotExist($this->repoRoot.'/lib/JIT/Builtin/phpc_array_replace_recursive.c');
    }

    public function testJitLoweringUsesArrayReplaceRecursiveRuntime(): void
    {
        $runtime = file_get_contents($this->repoRoot.'/lib/JIT/Builtin/ArrayReplaceRecursiveRuntime.php');
        $this->assertIsString($runtime);
        $this->assertStringContainsString('ArrayReplaceRecursiveJitHelper', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::arrayReplaceRecursive', $runtime);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = file_get_contents($this->repoRoot.'/ext/standard/array_replace_recursive.php');
        $this->assertIsString($builtin);
        $this->assertStringContainsString('ArrayReplaceRecursiveRuntime::replaceRecursive', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::arrayReplaceRecursive', $builtin);
        $this->assertStringContainsString('replaceRecursiveCopy', $builtin);
    }
}
