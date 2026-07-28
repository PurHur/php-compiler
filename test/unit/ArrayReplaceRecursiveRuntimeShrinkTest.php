<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\ArrayReplaceRecursiveJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * array_replace_recursive() NestedJIT via JitVmHelperLink::ensureCompiled (#12638 / #24077 / peer #23807).
 */
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

    public function testArrayReplaceRecursiveRuntimeUsesJitVmHelperLink(): void
    {
        $runtime = file_get_contents($this->repoRoot.'/lib/JIT/Builtin/ArrayReplaceRecursiveRuntime.php');
        $this->assertIsString($runtime);
        $this->assertStringContainsString('ArrayReplaceRecursiveJitHelper', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $runtime);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $runtime);
        $this->assertStringNotContainsString('parseAndCompile', $runtime);
        $this->assertStringNotContainsString('new JIT(', $runtime);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $runtime);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::arrayReplaceRecursive', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $runtime);

        $builtin = file_get_contents($this->repoRoot.'/ext/standard/array_replace_recursive.php');
        $this->assertIsString($builtin);
        $this->assertStringContainsString('ArrayReplaceRecursiveRuntime::replaceRecursive', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::arrayReplaceRecursive', $builtin);
        $this->assertStringContainsString('replaceRecursiveCopy', $builtin);
    }

    public function testArrayReplaceRecursiveJitHelperSingleCopy(): void
    {
        $base = new HashTable();
        $v = new Variable();
        $v->string('a');
        $base->add('x', $v);
        $copy = ArrayReplaceRecursiveJitHelper::replaceSingleCopy($base);
        $this->assertNotSame($base, $copy);
        $this->assertSame('a', $copy->find('x')->toString());
    }
}
