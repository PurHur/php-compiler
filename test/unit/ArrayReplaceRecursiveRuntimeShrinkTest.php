<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\ArrayReplaceRecursiveJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * array_replace_recursive() call-site LLVM (#12638 / #24077 / #26977).
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

    public function testArrayReplaceRecursiveRuntimeUsesCallSiteLlvm(): void
    {
        $runtime = file_get_contents($this->repoRoot.'/lib/JIT/Builtin/ArrayReplaceRecursiveRuntime.php');
        $this->assertIsString($runtime);
        $this->assertStringContainsString('HashTableReplaceRecursiveLlvm', $runtime);
        $this->assertStringContainsString('arrayReplaceRecursive', $runtime);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureCompiled', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::arrayReplaceRecursive', $runtime);

        $builtin = file_get_contents($this->repoRoot.'/ext/standard/array_replace_recursive.php');
        $this->assertIsString($builtin);
        $this->assertStringContainsString('ArrayReplaceRecursiveRuntime::replaceRecursive', $builtin);
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

    /** Nested overlay — helper must match HashTable::replaceRecursiveCopy (#26977). */
    public function testArrayReplaceRecursiveJitHelperNestedOverlayMatchesHashTable(): void
    {
        $left = new HashTable();
        $innerL = new HashTable();
        $b = new Variable();
        $b->int(1);
        $innerL->add('b', $b);
        $wrapL = new Variable();
        $wrapL->array($innerL);
        $left->add('a', $wrapL);

        $right = new HashTable();
        $innerR = new HashTable();
        $c = new Variable();
        $c->int(2);
        $innerR->add('c', $c);
        $wrapR = new Variable();
        $wrapR->array($innerR);
        $right->add('a', $wrapR);

        $viaHelper = ArrayReplaceRecursiveJitHelper::replaceTwo($left, $right);
        $viaHt = $left->replaceRecursiveCopy($right);

        $this->assertSame(1, $viaHelper->find('a')->toArray()->find('b')->toInt());
        $this->assertSame(2, $viaHelper->find('a')->toArray()->find('c')->toInt());
        $this->assertSame(1, $viaHt->find('a')->toArray()->find('b')->toInt());
        $this->assertSame(2, $viaHt->find('a')->toArray()->find('c')->toInt());
    }

    public function testNestedVmRegistersReplaceRecursiveCopy(): void
    {
        $nested = file_get_contents($this->repoRoot.'/lib/JIT/NestedVmHashTableMethodLlvm.php');
        $this->assertIsString($nested);
        $this->assertStringContainsString("'replacerecursivecopy'", $nested);
        $this->assertFileExists($this->repoRoot.'/lib/JIT/Call/HashTableReplaceRecursiveCopy.php');
        $this->assertFileExists($this->repoRoot.'/lib/JIT/HashTableReplaceRecursiveLlvm.php');
        $llvm = file_get_contents($this->repoRoot.'/lib/JIT/HashTableReplaceRecursiveLlvm.php');
        $this->assertIsString($llvm);
        $this->assertStringContainsString('JitValueBox::copyFromPointer', $llvm);
        $this->assertStringContainsString('__hashtable__replaceRecursiveOverlay', $llvm);
        $this->assertStringContainsString('ensureOverlayFunction', $llvm);
        $this->assertStringNotContainsString('HashTableDuplicateRuntime::duplicate', $llvm);
    }

    /** Done-when: json_encode(array_replace_recursive(lit)) folds via VM SSOT (#26977). */
    public function testJsonEncodeFoldsCompileTimeArrayReplaceRecursive(): void
    {
        $fold = file_get_contents($this->repoRoot.'/ext/standard/JitJsonEncodeCompileTime.php');
        $this->assertIsString($fold);
        $this->assertStringContainsString('tryCompileTimeArrayFromArrayReplaceRecursive', $fold);
        $this->assertStringContainsString('replaceRecursiveCopy', $fold);
        $this->assertFileExists($this->repoRoot.'/test/repro/aot_array_replace_recursive.php');
    }
}
