<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** array_walk() JIT routes through ArrayWalkRuntime PHP not ArrayBuiltinHelper LLVM (#18077, #14933). */
final class ArrayWalkRuntimeShrinkTest extends TestCase
{
    private const MONOLITH_BASELINE_LINES = 8800;

    public function testArrayWalkBuiltinDispatchesViaRuntimeBridge(): void
    {
        $walk = (string) file_get_contents(__DIR__.'/../../ext/standard/array_walk.php');
        $this->assertStringContainsString('ArrayWalkRuntime::walkInPlaceWithStringBuiltin', $walk);
        $this->assertStringContainsString('ArrayWalkRuntime::walkInPlaceWithClosure', $walk);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::walkInPlace', $walk);

        $walkRec = (string) file_get_contents(__DIR__.'/../../ext/standard/array_walk_recursive.php');
        $this->assertStringContainsString('ArrayWalkRuntime::walkRecursiveInPlaceWithStringBuiltin', $walkRec);
        $this->assertStringContainsString('ArrayWalkRuntime::walkRecursiveInPlaceWithClosure', $walkRec);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::walkRecursiveInPlace', $walkRec);
    }

    public function testArrayBuiltinHelperHasNoDeadWalkMonolith(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php');
        $this->assertStringNotContainsString('function walkInPlace', $source);
        $this->assertStringNotContainsString('function walkRecursiveInPlace', $source);
        $this->assertStringNotContainsString('walkInPlaceHashTable', $source);
        $this->assertStringNotContainsString('walkRecursiveStringKeys', $source);
    }

    public function testArrayWalkRuntimeLinksJitHelper(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayWalkRuntime.php');
        $this->assertStringContainsString('ArrayWalkJitHelper', $runtime);
        $this->assertStringContainsString('JitVmHelperLink', $runtime);
        $this->assertStringContainsString('ArrayWalkLlvm::walkRecursiveWithClosure', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::walkInPlace', $runtime);
    }

    public function testArrayBuiltinHelperLineCountShrunk(): void
    {
        $lines = substr_count((string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php'), "\n") + 1;
        $this->assertLessThan(self::MONOLITH_BASELINE_LINES, $lines);
    }
}
